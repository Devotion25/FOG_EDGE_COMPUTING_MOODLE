-- ═══════════════════════════════════════════════════════════════════════════════
--  DISTRIBUTED MOODLE DATABASE SYNCHRONIZATION
--  MySQL Database Schema and Triggers
-- ═══════════════════════════════════════════════════════════════════════════════
-- 
--  Project: Eventual Consistency in Distributed Moodle Learning Management Systems
--  Component: Database Change Capture (CDC) via MySQL Triggers
--  Version: 3.0.0 - WITH QUIZ SUPPORT
-- 
--  Purpose:
--  - Create the sync queue table to store pending changes
--  - Create triggers to capture INSERT/UPDATE/DELETE operations
--  - Supports: Courses, Quizzes, User Enrollments
-- 
--  Installation:
--  1. Run this script on EACH VM's MySQL database
--  2. IMPORTANT: Change 'VM1' to the appropriate VM name ('VM1', 'VM2', 'VM3')
-- 
--  Author: Distributed Moodle Sync Project
--  Version: 3.0.0
--  Last Updated: December 2025
-- 
-- ═══════════════════════════════════════════════════════════════════════════════

-- ═══════════════════════════════════════════════════════════════════════════════
--  CONFIGURATION
-- ═══════════════════════════════════════════════════════════════════════════════

-- !!! IMPORTANT: CHANGE THIS FOR EACH VM !!!
SET @VM_NAME = 'VM1';  -- Options: 'VM1', 'VM2', 'VM3'

-- Use the Moodle database
USE moodle;

-- ═══════════════════════════════════════════════════════════════════════════════
--  SYNC QUEUE TABLE
-- ═══════════════════════════════════════════════════════════════════════════════

-- Drop existing table if it exists (for clean reinstall)
DROP TABLE IF EXISTS mdl_yjs_sync_queue;

-- Create the sync queue table
CREATE TABLE mdl_yjs_sync_queue (
    -- Primary key
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    
    -- Change metadata
    table_name VARCHAR(64) NOT NULL COMMENT 'Source table name (e.g., mdl_course, mdl_quiz)',
    operation ENUM('INSERT', 'UPDATE', 'DELETE') NOT NULL COMMENT 'Type of operation',
    record_id BIGINT UNSIGNED NOT NULL COMMENT 'ID of the affected record',
    
    -- Change data
    data_json LONGTEXT NOT NULL COMMENT 'Full record data as JSON',
    
    -- Timing and source
    timestamp BIGINT UNSIGNED NOT NULL COMMENT 'Unix timestamp of change',
    source_vm VARCHAR(20) NOT NULL COMMENT 'VM that originated the change',
    
    -- Sync status
    synced TINYINT UNSIGNED DEFAULT 0 COMMENT '0=pending, 1=sent, 2=failed',
    sync_attempts TINYINT UNSIGNED DEFAULT 0 COMMENT 'Number of sync attempts',
    last_sync_attempt TIMESTAMP NULL COMMENT 'Last attempt timestamp',
    sync_error TEXT NULL COMMENT 'Error message if sync failed',
    
    -- Record timestamps
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'When record was created',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    -- Indexes for efficient querying
    INDEX idx_synced (synced),
    INDEX idx_source_vm (source_vm),
    INDEX idx_timestamp (timestamp),
    INDEX idx_table_operation (table_name, operation),
    INDEX idx_pending_changes (synced, source_vm, timestamp)
    
) ENGINE=InnoDB 
  DEFAULT CHARSET=utf8mb4 
  COLLATE=utf8mb4_unicode_ci
  COMMENT='Queue for pending database changes to be synced across VMs';

-- ═══════════════════════════════════════════════════════════════════════════════
--  COURSE TABLE TRIGGERS
-- ═══════════════════════════════════════════════════════════════════════════════

-- Drop existing triggers if they exist
DROP TRIGGER IF EXISTS sync_course_insert;
DROP TRIGGER IF EXISTS sync_course_update;
DROP TRIGGER IF EXISTS sync_course_delete;

DELIMITER //

-- ---------------------------------------------------------------------------
--  TRIGGER: sync_course_insert
--  Captures INSERT operations on mdl_course
-- ---------------------------------------------------------------------------
CREATE TRIGGER sync_course_insert
AFTER INSERT ON mdl_course
FOR EACH ROW
BEGIN
    INSERT INTO mdl_yjs_sync_queue (
        table_name,
        operation,
        record_id,
        data_json,
        timestamp,
        source_vm
    ) VALUES (
        'mdl_course',
        'INSERT',
        NEW.id,
        JSON_OBJECT(
            'id', NEW.id,
            'category', NEW.category,
            'sortorder', NEW.sortorder,
            'fullname', NEW.fullname,
            'shortname', NEW.shortname,
            'idnumber', NEW.idnumber,
            'summary', NEW.summary,
            'format', NEW.format,
            'startdate', NEW.startdate,
            'enddate', NEW.enddate,
            'visible', NEW.visible,
            'timecreated', NEW.timecreated,
            'timemodified', NEW.timemodified
        ),
        UNIX_TIMESTAMP(),
        @VM_NAME
    );
END //

-- ---------------------------------------------------------------------------
--  TRIGGER: sync_course_update
--  Captures UPDATE operations on mdl_course
-- ---------------------------------------------------------------------------
CREATE TRIGGER sync_course_update
AFTER UPDATE ON mdl_course
FOR EACH ROW
BEGIN
    -- Only capture changes to key fields to reduce sync traffic
    IF OLD.fullname != NEW.fullname 
       OR OLD.shortname != NEW.shortname 
       OR OLD.summary != NEW.summary
       OR OLD.visible != NEW.visible
       OR OLD.startdate != NEW.startdate
       OR OLD.enddate != NEW.enddate THEN
        
        INSERT INTO mdl_yjs_sync_queue (
            table_name,
            operation,
            record_id,
            data_json,
            timestamp,
            source_vm
        ) VALUES (
            'mdl_course',
            'UPDATE',
            NEW.id,
            JSON_OBJECT(
                'id', NEW.id,
                'category', NEW.category,
                'fullname', NEW.fullname,
                'shortname', NEW.shortname,
                'idnumber', NEW.idnumber,
                'summary', NEW.summary,
                'format', NEW.format,
                'startdate', NEW.startdate,
                'enddate', NEW.enddate,
                'visible', NEW.visible,
                'timemodified', NEW.timemodified
            ),
            UNIX_TIMESTAMP(),
            @VM_NAME
        );
    END IF;
END //

-- ---------------------------------------------------------------------------
--  TRIGGER: sync_course_delete
--  Captures DELETE operations on mdl_course
-- ---------------------------------------------------------------------------
CREATE TRIGGER sync_course_delete
AFTER DELETE ON mdl_course
FOR EACH ROW
BEGIN
    INSERT INTO mdl_yjs_sync_queue (
        table_name,
        operation,
        record_id,
        data_json,
        timestamp,
        source_vm
    ) VALUES (
        'mdl_course',
        'DELETE',
        OLD.id,
        JSON_OBJECT(
            'id', OLD.id,
            'fullname', OLD.fullname,
            'shortname', OLD.shortname
        ),
        UNIX_TIMESTAMP(),
        @VM_NAME
    );
END //

DELIMITER ;

-- ═══════════════════════════════════════════════════════════════════════════════
--  QUIZ TABLE TRIGGERS (ENABLED)
-- ═══════════════════════════════════════════════════════════════════════════════

-- Drop existing triggers if they exist
DROP TRIGGER IF EXISTS sync_quiz_insert;
DROP TRIGGER IF EXISTS sync_quiz_update;
DROP TRIGGER IF EXISTS sync_quiz_delete;

DELIMITER //

-- ---------------------------------------------------------------------------
--  TRIGGER: sync_quiz_insert
--  Captures INSERT operations on mdl_quiz
-- ---------------------------------------------------------------------------
CREATE TRIGGER sync_quiz_insert
AFTER INSERT ON mdl_quiz
FOR EACH ROW
BEGIN
    INSERT INTO mdl_yjs_sync_queue (
        table_name,
        operation,
        record_id,
        data_json,
        timestamp,
        source_vm
    ) VALUES (
        'mdl_quiz',
        'INSERT',
        NEW.id,
        JSON_OBJECT(
            'id', NEW.id,
            'course', NEW.course,
            'name', NEW.name,
            'intro', IFNULL(NEW.intro, ''),
            'introformat', NEW.introformat,
            'timeopen', NEW.timeopen,
            'timeclose', NEW.timeclose,
            'timelimit', NEW.timelimit,
            'overduehandling', NEW.overduehandling,
            'graceperiod', NEW.graceperiod,
            'preferredbehaviour', NEW.preferredbehaviour,
            'canredoquestions', NEW.canredoquestions,
            'attempts', NEW.attempts,
            'attemptonlast', NEW.attemptonlast,
            'grademethod', NEW.grademethod,
            'decimalpoints', NEW.decimalpoints,
            'questiondecimalpoints', NEW.questiondecimalpoints,
            'reviewattempt', NEW.reviewattempt,
            'reviewcorrectness', NEW.reviewcorrectness,
            'reviewmarks', NEW.reviewmarks,
            'reviewspecificfeedback', NEW.reviewspecificfeedback,
            'reviewgeneralfeedback', NEW.reviewgeneralfeedback,
            'reviewrightanswer', NEW.reviewrightanswer,
            'reviewoverallfeedback', NEW.reviewoverallfeedback,
            'questionsperpage', NEW.questionsperpage,
            'navmethod', NEW.navmethod,
            'shuffleanswers', NEW.shuffleanswers,
            'sumgrades', NEW.sumgrades,
            'grade', NEW.grade,
            'timecreated', NEW.timecreated,
            'timemodified', NEW.timemodified,
            'password', IFNULL(NEW.password, ''),
            'subnet', IFNULL(NEW.subnet, ''),
            'browsersecurity', NEW.browsersecurity,
            'delay1', NEW.delay1,
            'delay2', NEW.delay2,
            'showuserpicture', NEW.showuserpicture,
            'showblocks', NEW.showblocks,
            'completionattemptsexhausted', NEW.completionattemptsexhausted,
            'completionminattempts', NEW.completionminattempts,
            'allowofflineattempts', NEW.allowofflineattempts
        ),
        UNIX_TIMESTAMP(),
        @VM_NAME
    );
END //

-- ---------------------------------------------------------------------------
--  TRIGGER: sync_quiz_update
--  Captures UPDATE operations on mdl_quiz
--  Triggers on changes to name, settings, or timing
-- ---------------------------------------------------------------------------
CREATE TRIGGER sync_quiz_update
AFTER UPDATE ON mdl_quiz
FOR EACH ROW
BEGIN
    -- Capture changes to important quiz fields
    IF OLD.name != NEW.name 
       OR OLD.intro != NEW.intro
       OR OLD.timeopen != NEW.timeopen 
       OR OLD.timeclose != NEW.timeclose
       OR OLD.timelimit != NEW.timelimit
       OR OLD.attempts != NEW.attempts
       OR OLD.grade != NEW.grade
       OR OLD.grademethod != NEW.grademethod
       OR OLD.shuffleanswers != NEW.shuffleanswers
       OR OLD.questionsperpage != NEW.questionsperpage
       OR OLD.preferredbehaviour != NEW.preferredbehaviour
       OR OLD.password != NEW.password
       OR OLD.browsersecurity != NEW.browsersecurity THEN
        
        INSERT INTO mdl_yjs_sync_queue (
            table_name,
            operation,
            record_id,
            data_json,
            timestamp,
            source_vm
        ) VALUES (
            'mdl_quiz',
            'UPDATE',
            NEW.id,
            JSON_OBJECT(
                'id', NEW.id,
                'course', NEW.course,
                'name', NEW.name,
                'intro', IFNULL(NEW.intro, ''),
                'introformat', NEW.introformat,
                'timeopen', NEW.timeopen,
                'timeclose', NEW.timeclose,
                'timelimit', NEW.timelimit,
                'overduehandling', NEW.overduehandling,
                'graceperiod', NEW.graceperiod,
                'preferredbehaviour', NEW.preferredbehaviour,
                'canredoquestions', NEW.canredoquestions,
                'attempts', NEW.attempts,
                'attemptonlast', NEW.attemptonlast,
                'grademethod', NEW.grademethod,
                'decimalpoints', NEW.decimalpoints,
                'questiondecimalpoints', NEW.questiondecimalpoints,
                'reviewattempt', NEW.reviewattempt,
                'reviewcorrectness', NEW.reviewcorrectness,
                'reviewmarks', NEW.reviewmarks,
                'reviewspecificfeedback', NEW.reviewspecificfeedback,
                'reviewgeneralfeedback', NEW.reviewgeneralfeedback,
                'reviewrightanswer', NEW.reviewrightanswer,
                'reviewoverallfeedback', NEW.reviewoverallfeedback,
                'questionsperpage', NEW.questionsperpage,
                'navmethod', NEW.navmethod,
                'shuffleanswers', NEW.shuffleanswers,
                'sumgrades', NEW.sumgrades,
                'grade', NEW.grade,
                'timemodified', NEW.timemodified,
                'password', IFNULL(NEW.password, ''),
                'subnet', IFNULL(NEW.subnet, ''),
                'browsersecurity', NEW.browsersecurity,
                'delay1', NEW.delay1,
                'delay2', NEW.delay2,
                'showuserpicture', NEW.showuserpicture,
                'showblocks', NEW.showblocks,
                'completionattemptsexhausted', NEW.completionattemptsexhausted,
                'completionminattempts', NEW.completionminattempts,
                'allowofflineattempts', NEW.allowofflineattempts
            ),
            UNIX_TIMESTAMP(),
            @VM_NAME
        );
    END IF;
END //

-- ---------------------------------------------------------------------------
--  TRIGGER: sync_quiz_delete
--  Captures DELETE operations on mdl_quiz
-- ---------------------------------------------------------------------------
CREATE TRIGGER sync_quiz_delete
AFTER DELETE ON mdl_quiz
FOR EACH ROW
BEGIN
    INSERT INTO mdl_yjs_sync_queue (
        table_name,
        operation,
        record_id,
        data_json,
        timestamp,
        source_vm
    ) VALUES (
        'mdl_quiz',
        'DELETE',
        OLD.id,
        JSON_OBJECT(
            'id', OLD.id,
            'course', OLD.course,
            'name', OLD.name
        ),
        UNIX_TIMESTAMP(),
        @VM_NAME
    );
END //

DELIMITER ;

-- ═══════════════════════════════════════════════════════════════════════════════
--  USER ENROLLMENT TRIGGERS (ENABLED)
-- ═══════════════════════════════════════════════════════════════════════════════

-- Drop existing triggers if they exist
DROP TRIGGER IF EXISTS sync_user_enrolments_insert;
DROP TRIGGER IF EXISTS sync_user_enrolments_update;
DROP TRIGGER IF EXISTS sync_user_enrolments_delete;

DELIMITER //

-- ---------------------------------------------------------------------------
--  TRIGGER: sync_user_enrolments_insert
--  Captures INSERT operations on mdl_user_enrolments
-- ---------------------------------------------------------------------------
CREATE TRIGGER sync_user_enrolments_insert
AFTER INSERT ON mdl_user_enrolments
FOR EACH ROW
BEGIN
    INSERT INTO mdl_yjs_sync_queue (
        table_name,
        operation,
        record_id,
        data_json,
        timestamp,
        source_vm
    ) VALUES (
        'mdl_user_enrolments',
        'INSERT',
        NEW.id,
        JSON_OBJECT(
            'id', NEW.id,
            'status', NEW.status,
            'enrolid', NEW.enrolid,
            'userid', NEW.userid,
            'timestart', NEW.timestart,
            'timeend', NEW.timeend,
            'modifierid', NEW.modifierid,
            'timecreated', NEW.timecreated,
            'timemodified', NEW.timemodified
        ),
        UNIX_TIMESTAMP(),
        @VM_NAME
    );
END //

-- ---------------------------------------------------------------------------
--  TRIGGER: sync_user_enrolments_update
--  Captures UPDATE operations on mdl_user_enrolments
-- ---------------------------------------------------------------------------
CREATE TRIGGER sync_user_enrolments_update
AFTER UPDATE ON mdl_user_enrolments
FOR EACH ROW
BEGIN
    IF OLD.status != NEW.status 
       OR OLD.timestart != NEW.timestart 
       OR OLD.timeend != NEW.timeend THEN
        
        INSERT INTO mdl_yjs_sync_queue (
            table_name,
            operation,
            record_id,
            data_json,
            timestamp,
            source_vm
        ) VALUES (
            'mdl_user_enrolments',
            'UPDATE',
            NEW.id,
            JSON_OBJECT(
                'id', NEW.id,
                'status', NEW.status,
                'enrolid', NEW.enrolid,
                'userid', NEW.userid,
                'timestart', NEW.timestart,
                'timeend', NEW.timeend,
                'modifierid', NEW.modifierid,
                'timemodified', NEW.timemodified
            ),
            UNIX_TIMESTAMP(),
            @VM_NAME
        );
    END IF;
END //

-- ---------------------------------------------------------------------------
--  TRIGGER: sync_user_enrolments_delete
--  Captures DELETE operations on mdl_user_enrolments
-- ---------------------------------------------------------------------------
CREATE TRIGGER sync_user_enrolments_delete
AFTER DELETE ON mdl_user_enrolments
FOR EACH ROW
BEGIN
    INSERT INTO mdl_yjs_sync_queue (
        table_name,
        operation,
        record_id,
        data_json,
        timestamp,
        source_vm
    ) VALUES (
        'mdl_user_enrolments',
        'DELETE',
        OLD.id,
        JSON_OBJECT(
            'id', OLD.id,
            'enrolid', OLD.enrolid,
            'userid', OLD.userid
        ),
        UNIX_TIMESTAMP(),
        @VM_NAME
    );
END //

DELIMITER ;

-- ═══════════════════════════════════════════════════════════════════════════════
--  COURSE MODULES TABLE TRIGGERS (ENABLED)
-- ═══════════════════════════════════════════════════════════════════════════════

-- Drop existing triggers if they exist
DROP TRIGGER IF EXISTS sync_course_modules_insert;
DROP TRIGGER IF EXISTS sync_course_modules_update;
DROP TRIGGER IF EXISTS sync_course_modules_delete;

DELIMITER //

-- ---------------------------------------------------------------------------
--  TRIGGER: sync_course_modules_insert
-- ---------------------------------------------------------------------------
CREATE TRIGGER sync_course_modules_insert
AFTER INSERT ON mdl_course_modules
FOR EACH ROW
BEGIN
    INSERT INTO mdl_yjs_sync_queue (
        table_name,
        operation,
        record_id,
        data_json,
        timestamp,
        source_vm
    ) VALUES (
        'mdl_course_modules',
        'INSERT',
        NEW.id,
        JSON_OBJECT(
            'id', NEW.id,
            'course', NEW.course,
            'module', NEW.module,
            'instance', NEW.instance,
            'section', NEW.section,
            'idnumber', IFNULL(NEW.idnumber, ''),
            'added', NEW.added,
            'score', NEW.score,
            'indent', NEW.indent,
            'visible', NEW.visible,
            'visibleoncoursepage', NEW.visibleoncoursepage,
            'visibleold', NEW.visibleold,
            'groupmode', NEW.groupmode,
            'groupingid', NEW.groupingid,
            'completion', NEW.completion,
            'completiongradeitemnumber', NEW.completiongradeitemnumber,
            'completionview', NEW.completionview,
            'completionexpected', NEW.completionexpected,
            'showdescription', NEW.showdescription,
            'availability', IFNULL(NEW.availability, ''),
            'deletioninprogress', NEW.deletioninprogress
        ),
        UNIX_TIMESTAMP(),
        @VM_NAME
    );
END //

-- ---------------------------------------------------------------------------
--  TRIGGER: sync_course_modules_update
-- ---------------------------------------------------------------------------
CREATE TRIGGER sync_course_modules_update
AFTER UPDATE ON mdl_course_modules
FOR EACH ROW
BEGIN
    IF OLD.visible != NEW.visible 
       OR OLD.section != NEW.section
       OR OLD.indent != NEW.indent
       OR OLD.completion != NEW.completion
       OR OLD.availability != NEW.availability THEN
        
        INSERT INTO mdl_yjs_sync_queue (
            table_name,
            operation,
            record_id,
            data_json,
            timestamp,
            source_vm
        ) VALUES (
            'mdl_course_modules',
            'UPDATE',
            NEW.id,
            JSON_OBJECT(
                'id', NEW.id,
                'course', NEW.course,
                'module', NEW.module,
                'instance', NEW.instance,
                'section', NEW.section,
                'idnumber', IFNULL(NEW.idnumber, ''),
                'score', NEW.score,
                'indent', NEW.indent,
                'visible', NEW.visible,
                'visibleoncoursepage', NEW.visibleoncoursepage,
                'visibleold', NEW.visibleold,
                'groupmode', NEW.groupmode,
                'groupingid', NEW.groupingid,
                'completion', NEW.completion,
                'completiongradeitemnumber', NEW.completiongradeitemnumber,
                'completionview', NEW.completionview,
                'completionexpected', NEW.completionexpected,
                'showdescription', NEW.showdescription,
                'availability', IFNULL(NEW.availability, ''),
                'deletioninprogress', NEW.deletioninprogress
            ),
            UNIX_TIMESTAMP(),
            @VM_NAME
        );
    END IF;
END //

-- ---------------------------------------------------------------------------
--  TRIGGER: sync_course_modules_delete
-- ---------------------------------------------------------------------------
CREATE TRIGGER sync_course_modules_delete
AFTER DELETE ON mdl_course_modules
FOR EACH ROW
BEGIN
    INSERT INTO mdl_yjs_sync_queue (
        table_name,
        operation,
        record_id,
        data_json,
        timestamp,
        source_vm
    ) VALUES (
        'mdl_course_modules',
        'DELETE',
        OLD.id,
        JSON_OBJECT(
            'id', OLD.id,
            'course', OLD.course,
            'module', OLD.module,
            'instance', OLD.instance
        ),
        UNIX_TIMESTAMP(),
        @VM_NAME
    );
END //

DELIMITER ;

-- ═══════════════════════════════════════════════════════════════════════════════
--  ASSIGNMENT TABLE TRIGGERS (mdl_assign)
-- ═══════════════════════════════════════════════════════════════════════════════

DROP TRIGGER IF EXISTS sync_assign_insert;
DROP TRIGGER IF EXISTS sync_assign_update;
DROP TRIGGER IF EXISTS sync_assign_delete;

DELIMITER //

-- ---------------------------------------------------------------------------
--  TRIGGER: sync_assign_insert
-- ---------------------------------------------------------------------------
CREATE TRIGGER sync_assign_insert
AFTER INSERT ON mdl_assign
FOR EACH ROW
BEGIN
    INSERT INTO mdl_yjs_sync_queue (
        table_name, operation, record_id, data_json, timestamp, source_vm
    ) VALUES (
        'mdl_assign',
        'INSERT',
        NEW.id,
        JSON_OBJECT(
            'id', NEW.id,
            'course', NEW.course,
            'name', NEW.name,
            'intro', IFNULL(NEW.intro, ''),
            'introformat', NEW.introformat,
            'alwaysshowdescription', NEW.alwaysshowdescription,
            'nosubmissions', NEW.nosubmissions,
            'submissiondrafts', NEW.submissiondrafts,
            'sendnotifications', NEW.sendnotifications,
            'sendlatenotifications', NEW.sendlatenotifications,
            'duedate', NEW.duedate,
            'allowsubmissionsfromdate', NEW.allowsubmissionsfromdate,
            'grade', NEW.grade,
            'timemodified', NEW.timemodified,
            'requiresubmissionstatement', NEW.requiresubmissionstatement,
            'completionsubmit', NEW.completionsubmit,
            'cutoffdate', NEW.cutoffdate,
            'gradingduedate', NEW.gradingduedate,
            'teamsubmission', NEW.teamsubmission,
            'requireallteammemberssubmit', NEW.requireallteammemberssubmit,
            'teamsubmissiongroupingid', NEW.teamsubmissiongroupingid,
            'blindmarking', NEW.blindmarking,
            'hidegrader', NEW.hidegrader,
            'revealidentities', NEW.revealidentities,
            'attemptreopenmethod', NEW.attemptreopenmethod,
            'maxattempts', NEW.maxattempts,
            'markingworkflow', NEW.markingworkflow,
            'markingallocation', NEW.markingallocation,
            'sendstudentnotifications', NEW.sendstudentnotifications,
            'preventsubmissionnotingroup', NEW.preventsubmissionnotingroup
        ),
        UNIX_TIMESTAMP(),
        @VM_NAME
    );
END //

-- ---------------------------------------------------------------------------
--  TRIGGER: sync_assign_update
-- ---------------------------------------------------------------------------
CREATE TRIGGER sync_assign_update
AFTER UPDATE ON mdl_assign
FOR EACH ROW
BEGIN
    IF OLD.name != NEW.name 
       OR OLD.intro != NEW.intro
       OR OLD.duedate != NEW.duedate 
       OR OLD.allowsubmissionsfromdate != NEW.allowsubmissionsfromdate
       OR OLD.cutoffdate != NEW.cutoffdate
       OR OLD.grade != NEW.grade
       OR OLD.submissiondrafts != NEW.submissiondrafts
       OR OLD.maxattempts != NEW.maxattempts THEN
        
        INSERT INTO mdl_yjs_sync_queue (
            table_name, operation, record_id, data_json, timestamp, source_vm
        ) VALUES (
            'mdl_assign',
            'UPDATE',
            NEW.id,
            JSON_OBJECT(
                'id', NEW.id,
                'course', NEW.course,
                'name', NEW.name,
                'intro', IFNULL(NEW.intro, ''),
                'introformat', NEW.introformat,
                'alwaysshowdescription', NEW.alwaysshowdescription,
                'nosubmissions', NEW.nosubmissions,
                'submissiondrafts', NEW.submissiondrafts,
                'sendnotifications', NEW.sendnotifications,
                'sendlatenotifications', NEW.sendlatenotifications,
                'duedate', NEW.duedate,
                'allowsubmissionsfromdate', NEW.allowsubmissionsfromdate,
                'grade', NEW.grade,
                'timemodified', NEW.timemodified,
                'requiresubmissionstatement', NEW.requiresubmissionstatement,
                'completionsubmit', NEW.completionsubmit,
                'cutoffdate', NEW.cutoffdate,
                'gradingduedate', NEW.gradingduedate,
                'teamsubmission', NEW.teamsubmission,
                'requireallteammemberssubmit', NEW.requireallteammemberssubmit,
                'teamsubmissiongroupingid', NEW.teamsubmissiongroupingid,
                'blindmarking', NEW.blindmarking,
                'hidegrader', NEW.hidegrader,
                'revealidentities', NEW.revealidentities,
                'attemptreopenmethod', NEW.attemptreopenmethod,
                'maxattempts', NEW.maxattempts,
                'markingworkflow', NEW.markingworkflow,
                'markingallocation', NEW.markingallocation,
                'sendstudentnotifications', NEW.sendstudentnotifications,
                'preventsubmissionnotingroup', NEW.preventsubmissionnotingroup
            ),
            UNIX_TIMESTAMP(),
            @VM_NAME
        );
    END IF;
END //

-- ---------------------------------------------------------------------------
--  TRIGGER: sync_assign_delete
-- ---------------------------------------------------------------------------
CREATE TRIGGER sync_assign_delete
AFTER DELETE ON mdl_assign
FOR EACH ROW
BEGIN
    INSERT INTO mdl_yjs_sync_queue (
        table_name, operation, record_id, data_json, timestamp, source_vm
    ) VALUES (
        'mdl_assign',
        'DELETE',
        OLD.id,
        JSON_OBJECT('id', OLD.id, 'course', OLD.course, 'name', OLD.name),
        UNIX_TIMESTAMP(),
        @VM_NAME
    );
END //

DELIMITER ;

-- ═══════════════════════════════════════════════════════════════════════════════
--  QUESTION BANK TRIGGERS (mdl_question)
-- ═══════════════════════════════════════════════════════════════════════════════

DROP TRIGGER IF EXISTS sync_question_insert;
DROP TRIGGER IF EXISTS sync_question_update;
DROP TRIGGER IF EXISTS sync_question_delete;

DELIMITER //

-- ---------------------------------------------------------------------------
--  TRIGGER: sync_question_insert
-- ---------------------------------------------------------------------------
CREATE TRIGGER sync_question_insert
AFTER INSERT ON mdl_question
FOR EACH ROW
BEGIN
    INSERT INTO mdl_yjs_sync_queue (
        table_name, operation, record_id, data_json, timestamp, source_vm
    ) VALUES (
        'mdl_question',
        'INSERT',
        NEW.id,
        JSON_OBJECT(
            'id', NEW.id,
            'category', NEW.category,
            'parent', NEW.parent,
            'name', NEW.name,
            'questiontext', IFNULL(NEW.questiontext, ''),
            'questiontextformat', NEW.questiontextformat,
            'generalfeedback', IFNULL(NEW.generalfeedback, ''),
            'generalfeedbackformat', NEW.generalfeedbackformat,
            'defaultmark', NEW.defaultmark,
            'penalty', NEW.penalty,
            'qtype', NEW.qtype,
            'length', NEW.length,
            'stamp', NEW.stamp,
            'version', NEW.version,
            'hidden', NEW.hidden,
            'timecreated', NEW.timecreated,
            'timemodified', NEW.timemodified,
            'createdby', NEW.createdby,
            'modifiedby', NEW.modifiedby,
            'idnumber', IFNULL(NEW.idnumber, '')
        ),
        UNIX_TIMESTAMP(),
        @VM_NAME
    );
END //

-- ---------------------------------------------------------------------------
--  TRIGGER: sync_question_update
-- ---------------------------------------------------------------------------
CREATE TRIGGER sync_question_update
AFTER UPDATE ON mdl_question
FOR EACH ROW
BEGIN
    IF OLD.name != NEW.name 
       OR OLD.questiontext != NEW.questiontext
       OR OLD.generalfeedback != NEW.generalfeedback
       OR OLD.defaultmark != NEW.defaultmark
       OR OLD.penalty != NEW.penalty
       OR OLD.hidden != NEW.hidden THEN
        
        INSERT INTO mdl_yjs_sync_queue (
            table_name, operation, record_id, data_json, timestamp, source_vm
        ) VALUES (
            'mdl_question',
            'UPDATE',
            NEW.id,
            JSON_OBJECT(
                'id', NEW.id,
                'category', NEW.category,
                'parent', NEW.parent,
                'name', NEW.name,
                'questiontext', IFNULL(NEW.questiontext, ''),
                'questiontextformat', NEW.questiontextformat,
                'generalfeedback', IFNULL(NEW.generalfeedback, ''),
                'generalfeedbackformat', NEW.generalfeedbackformat,
                'defaultmark', NEW.defaultmark,
                'penalty', NEW.penalty,
                'qtype', NEW.qtype,
                'length', NEW.length,
                'stamp', NEW.stamp,
                'version', NEW.version,
                'hidden', NEW.hidden,
                'timemodified', NEW.timemodified,
                'modifiedby', NEW.modifiedby,
                'idnumber', IFNULL(NEW.idnumber, '')
            ),
            UNIX_TIMESTAMP(),
            @VM_NAME
        );
    END IF;
END //

-- ---------------------------------------------------------------------------
--  TRIGGER: sync_question_delete
-- ---------------------------------------------------------------------------
CREATE TRIGGER sync_question_delete
AFTER DELETE ON mdl_question
FOR EACH ROW
BEGIN
    INSERT INTO mdl_yjs_sync_queue (
        table_name, operation, record_id, data_json, timestamp, source_vm
    ) VALUES (
        'mdl_question',
        'DELETE',
        OLD.id,
        JSON_OBJECT('id', OLD.id, 'category', OLD.category, 'name', OLD.name, 'qtype', OLD.qtype),
        UNIX_TIMESTAMP(),
        @VM_NAME
    );
END //

DELIMITER ;

-- ═══════════════════════════════════════════════════════════════════════════════
--  GRADE ITEMS TRIGGERS (mdl_grade_items)
-- ═══════════════════════════════════════════════════════════════════════════════

DROP TRIGGER IF EXISTS sync_grade_items_insert;
DROP TRIGGER IF EXISTS sync_grade_items_update;
DROP TRIGGER IF EXISTS sync_grade_items_delete;

DELIMITER //

-- ---------------------------------------------------------------------------
--  TRIGGER: sync_grade_items_insert
-- ---------------------------------------------------------------------------
CREATE TRIGGER sync_grade_items_insert
AFTER INSERT ON mdl_grade_items
FOR EACH ROW
BEGIN
    INSERT INTO mdl_yjs_sync_queue (
        table_name, operation, record_id, data_json, timestamp, source_vm
    ) VALUES (
        'mdl_grade_items',
        'INSERT',
        NEW.id,
        JSON_OBJECT(
            'id', NEW.id,
            'courseid', NEW.courseid,
            'categoryid', NEW.categoryid,
            'itemname', IFNULL(NEW.itemname, ''),
            'itemtype', NEW.itemtype,
            'itemmodule', IFNULL(NEW.itemmodule, ''),
            'iteminstance', NEW.iteminstance,
            'itemnumber', NEW.itemnumber,
            'iteminfo', IFNULL(NEW.iteminfo, ''),
            'idnumber', IFNULL(NEW.idnumber, ''),
            'calculation', IFNULL(NEW.calculation, ''),
            'gradetype', NEW.gradetype,
            'grademax', NEW.grademax,
            'grademin', NEW.grademin,
            'scaleid', NEW.scaleid,
            'outcomeid', NEW.outcomeid,
            'gradepass', NEW.gradepass,
            'multfactor', NEW.multfactor,
            'plusfactor', NEW.plusfactor,
            'aggregationcoef', NEW.aggregationcoef,
            'aggregationcoef2', NEW.aggregationcoef2,
            'sortorder', NEW.sortorder,
            'display', NEW.display,
            'decimals', NEW.decimals,
            'hidden', NEW.hidden,
            'locked', NEW.locked,
            'locktime', NEW.locktime,
            'needsupdate', NEW.needsupdate,
            'weightoverride', NEW.weightoverride,
            'timecreated', NEW.timecreated,
            'timemodified', NEW.timemodified
        ),
        UNIX_TIMESTAMP(),
        @VM_NAME
    );
END //

-- ---------------------------------------------------------------------------
--  TRIGGER: sync_grade_items_update
-- ---------------------------------------------------------------------------
CREATE TRIGGER sync_grade_items_update
AFTER UPDATE ON mdl_grade_items
FOR EACH ROW
BEGIN
    IF OLD.itemname != NEW.itemname 
       OR OLD.grademax != NEW.grademax
       OR OLD.grademin != NEW.grademin
       OR OLD.gradepass != NEW.gradepass
       OR OLD.hidden != NEW.hidden
       OR OLD.locked != NEW.locked
       OR OLD.calculation != NEW.calculation THEN
        
        INSERT INTO mdl_yjs_sync_queue (
            table_name, operation, record_id, data_json, timestamp, source_vm
        ) VALUES (
            'mdl_grade_items',
            'UPDATE',
            NEW.id,
            JSON_OBJECT(
                'id', NEW.id,
                'courseid', NEW.courseid,
                'categoryid', NEW.categoryid,
                'itemname', IFNULL(NEW.itemname, ''),
                'itemtype', NEW.itemtype,
                'itemmodule', IFNULL(NEW.itemmodule, ''),
                'iteminstance', NEW.iteminstance,
                'itemnumber', NEW.itemnumber,
                'gradetype', NEW.gradetype,
                'grademax', NEW.grademax,
                'grademin', NEW.grademin,
                'gradepass', NEW.gradepass,
                'multfactor', NEW.multfactor,
                'plusfactor', NEW.plusfactor,
                'hidden', NEW.hidden,
                'locked', NEW.locked,
                'locktime', NEW.locktime,
                'timemodified', NEW.timemodified
            ),
            UNIX_TIMESTAMP(),
            @VM_NAME
        );
    END IF;
END //

-- ---------------------------------------------------------------------------
--  TRIGGER: sync_grade_items_delete
-- ---------------------------------------------------------------------------
CREATE TRIGGER sync_grade_items_delete
AFTER DELETE ON mdl_grade_items
FOR EACH ROW
BEGIN
    INSERT INTO mdl_yjs_sync_queue (
        table_name, operation, record_id, data_json, timestamp, source_vm
    ) VALUES (
        'mdl_grade_items',
        'DELETE',
        OLD.id,
        JSON_OBJECT('id', OLD.id, 'courseid', OLD.courseid, 'itemname', IFNULL(OLD.itemname, ''), 'itemtype', OLD.itemtype),
        UNIX_TIMESTAMP(),
        @VM_NAME
    );
END //

DELIMITER ;

-- ═══════════════════════════════════════════════════════════════════════════════
--  GRADE GRADES TRIGGERS (mdl_grade_grades) - Student Grades
-- ═══════════════════════════════════════════════════════════════════════════════

DROP TRIGGER IF EXISTS sync_grade_grades_insert;
DROP TRIGGER IF EXISTS sync_grade_grades_update;
DROP TRIGGER IF EXISTS sync_grade_grades_delete;

DELIMITER //

-- ---------------------------------------------------------------------------
--  TRIGGER: sync_grade_grades_insert
-- ---------------------------------------------------------------------------
CREATE TRIGGER sync_grade_grades_insert
AFTER INSERT ON mdl_grade_grades
FOR EACH ROW
BEGIN
    INSERT INTO mdl_yjs_sync_queue (
        table_name, operation, record_id, data_json, timestamp, source_vm
    ) VALUES (
        'mdl_grade_grades',
        'INSERT',
        NEW.id,
        JSON_OBJECT(
            'id', NEW.id,
            'itemid', NEW.itemid,
            'userid', NEW.userid,
            'rawgrade', NEW.rawgrade,
            'rawgrademax', NEW.rawgrademax,
            'rawgrademin', NEW.rawgrademin,
            'rawscaleid', NEW.rawscaleid,
            'usermodified', NEW.usermodified,
            'finalgrade', NEW.finalgrade,
            'hidden', NEW.hidden,
            'locked', NEW.locked,
            'locktime', NEW.locktime,
            'exported', NEW.exported,
            'overridden', NEW.overridden,
            'excluded', NEW.excluded,
            'feedback', IFNULL(NEW.feedback, ''),
            'feedbackformat', NEW.feedbackformat,
            'information', IFNULL(NEW.information, ''),
            'informationformat', NEW.informationformat,
            'timecreated', NEW.timecreated,
            'timemodified', NEW.timemodified,
            'aggregationstatus', NEW.aggregationstatus,
            'aggregationweight', NEW.aggregationweight
        ),
        UNIX_TIMESTAMP(),
        @VM_NAME
    );
END //

-- ---------------------------------------------------------------------------
--  TRIGGER: sync_grade_grades_update
-- ---------------------------------------------------------------------------
CREATE TRIGGER sync_grade_grades_update
AFTER UPDATE ON mdl_grade_grades
FOR EACH ROW
BEGIN
    IF OLD.rawgrade != NEW.rawgrade 
       OR OLD.finalgrade != NEW.finalgrade
       OR OLD.feedback != NEW.feedback
       OR OLD.hidden != NEW.hidden
       OR OLD.locked != NEW.locked
       OR OLD.overridden != NEW.overridden
       OR OLD.excluded != NEW.excluded THEN
        
        INSERT INTO mdl_yjs_sync_queue (
            table_name, operation, record_id, data_json, timestamp, source_vm
        ) VALUES (
            'mdl_grade_grades',
            'UPDATE',
            NEW.id,
            JSON_OBJECT(
                'id', NEW.id,
                'itemid', NEW.itemid,
                'userid', NEW.userid,
                'rawgrade', NEW.rawgrade,
                'rawgrademax', NEW.rawgrademax,
                'rawgrademin', NEW.rawgrademin,
                'usermodified', NEW.usermodified,
                'finalgrade', NEW.finalgrade,
                'hidden', NEW.hidden,
                'locked', NEW.locked,
                'locktime', NEW.locktime,
                'overridden', NEW.overridden,
                'excluded', NEW.excluded,
                'feedback', IFNULL(NEW.feedback, ''),
                'feedbackformat', NEW.feedbackformat,
                'timemodified', NEW.timemodified
            ),
            UNIX_TIMESTAMP(),
            @VM_NAME
        );
    END IF;
END //

-- ---------------------------------------------------------------------------
--  TRIGGER: sync_grade_grades_delete
-- ---------------------------------------------------------------------------
CREATE TRIGGER sync_grade_grades_delete
AFTER DELETE ON mdl_grade_grades
FOR EACH ROW
BEGIN
    INSERT INTO mdl_yjs_sync_queue (
        table_name, operation, record_id, data_json, timestamp, source_vm
    ) VALUES (
        'mdl_grade_grades',
        'DELETE',
        OLD.id,
        JSON_OBJECT('id', OLD.id, 'itemid', OLD.itemid, 'userid', OLD.userid),
        UNIX_TIMESTAMP(),
        @VM_NAME
    );
END //

DELIMITER ;

-- ═══════════════════════════════════════════════════════════════════════════════
--  FORUM TRIGGERS (mdl_forum)
-- ═══════════════════════════════════════════════════════════════════════════════

DROP TRIGGER IF EXISTS sync_forum_insert;
DROP TRIGGER IF EXISTS sync_forum_update;
DROP TRIGGER IF EXISTS sync_forum_delete;

DELIMITER //

-- ---------------------------------------------------------------------------
--  TRIGGER: sync_forum_insert
-- ---------------------------------------------------------------------------
CREATE TRIGGER sync_forum_insert
AFTER INSERT ON mdl_forum
FOR EACH ROW
BEGIN
    INSERT INTO mdl_yjs_sync_queue (
        table_name, operation, record_id, data_json, timestamp, source_vm
    ) VALUES (
        'mdl_forum',
        'INSERT',
        NEW.id,
        JSON_OBJECT(
            'id', NEW.id,
            'course', NEW.course,
            'type', NEW.type,
            'name', NEW.name,
            'intro', IFNULL(NEW.intro, ''),
            'introformat', NEW.introformat,
            'duedate', NEW.duedate,
            'cutoffdate', NEW.cutoffdate,
            'assessed', NEW.assessed,
            'assesstimestart', NEW.assesstimestart,
            'assesstimefinish', NEW.assesstimefinish,
            'scale', NEW.scale,
            'grade_forum', NEW.grade_forum,
            'grade_forum_notify', NEW.grade_forum_notify,
            'maxbytes', NEW.maxbytes,
            'maxattachments', NEW.maxattachments,
            'forcesubscribe', NEW.forcesubscribe,
            'trackingtype', NEW.trackingtype,
            'rsstype', NEW.rsstype,
            'rssarticles', NEW.rssarticles,
            'timemodified', NEW.timemodified,
            'warnafter', NEW.warnafter,
            'blockafter', NEW.blockafter,
            'blockperiod', NEW.blockperiod,
            'completiondiscussions', NEW.completiondiscussions,
            'completionreplies', NEW.completionreplies,
            'completionposts', NEW.completionposts,
            'displaywordcount', NEW.displaywordcount,
            'lockdiscussionafter', NEW.lockdiscussionafter
        ),
        UNIX_TIMESTAMP(),
        @VM_NAME
    );
END //

-- ---------------------------------------------------------------------------
--  TRIGGER: sync_forum_update
-- ---------------------------------------------------------------------------
CREATE TRIGGER sync_forum_update
AFTER UPDATE ON mdl_forum
FOR EACH ROW
BEGIN
    IF OLD.name != NEW.name 
       OR OLD.intro != NEW.intro
       OR OLD.type != NEW.type
       OR OLD.duedate != NEW.duedate
       OR OLD.forcesubscribe != NEW.forcesubscribe THEN
        
        INSERT INTO mdl_yjs_sync_queue (
            table_name, operation, record_id, data_json, timestamp, source_vm
        ) VALUES (
            'mdl_forum',
            'UPDATE',
            NEW.id,
            JSON_OBJECT(
                'id', NEW.id,
                'course', NEW.course,
                'type', NEW.type,
                'name', NEW.name,
                'intro', IFNULL(NEW.intro, ''),
                'introformat', NEW.introformat,
                'duedate', NEW.duedate,
                'cutoffdate', NEW.cutoffdate,
                'forcesubscribe', NEW.forcesubscribe,
                'trackingtype', NEW.trackingtype,
                'timemodified', NEW.timemodified,
                'lockdiscussionafter', NEW.lockdiscussionafter
            ),
            UNIX_TIMESTAMP(),
            @VM_NAME
        );
    END IF;
END //

-- ---------------------------------------------------------------------------
--  TRIGGER: sync_forum_delete
-- ---------------------------------------------------------------------------
CREATE TRIGGER sync_forum_delete
AFTER DELETE ON mdl_forum
FOR EACH ROW
BEGIN
    INSERT INTO mdl_yjs_sync_queue (
        table_name, operation, record_id, data_json, timestamp, source_vm
    ) VALUES (
        'mdl_forum',
        'DELETE',
        OLD.id,
        JSON_OBJECT('id', OLD.id, 'course', OLD.course, 'name', OLD.name),
        UNIX_TIMESTAMP(),
        @VM_NAME
    );
END //

DELIMITER ;

-- ═══════════════════════════════════════════════════════════════════════════════
--  UTILITY PROCEDURES
-- ═══════════════════════════════════════════════════════════════════════════════

DELIMITER //

-- ---------------------------------------------------------------------------
--  PROCEDURE: cleanup_sync_queue
--  Removes old synced records to prevent table bloat
--  Usage: CALL cleanup_sync_queue(7);  -- Keep last 7 days
-- ---------------------------------------------------------------------------
DROP PROCEDURE IF EXISTS cleanup_sync_queue //

CREATE PROCEDURE cleanup_sync_queue(IN days_to_keep INT)
BEGIN
    DECLARE cutoff_timestamp BIGINT;
    SET cutoff_timestamp = UNIX_TIMESTAMP() - (days_to_keep * 86400);
    
    DELETE FROM mdl_yjs_sync_queue 
    WHERE synced = 1 
      AND timestamp < cutoff_timestamp;
    
    SELECT ROW_COUNT() AS deleted_rows;
END //

-- ---------------------------------------------------------------------------
--  PROCEDURE: get_sync_stats
--  Returns statistics about the sync queue
--  Usage: CALL get_sync_stats();
-- ---------------------------------------------------------------------------
DROP PROCEDURE IF EXISTS get_sync_stats //

CREATE PROCEDURE get_sync_stats()
BEGIN
    SELECT 
        source_vm,
        table_name,
        operation,
        synced,
        COUNT(*) as count,
        MIN(FROM_UNIXTIME(timestamp)) as oldest,
        MAX(FROM_UNIXTIME(timestamp)) as newest
    FROM mdl_yjs_sync_queue
    GROUP BY source_vm, table_name, operation, synced
    ORDER BY source_vm, table_name, operation, synced;
END //

-- ---------------------------------------------------------------------------
--  PROCEDURE: retry_failed_syncs
--  Resets failed sync attempts for retry
--  Usage: CALL retry_failed_syncs();
-- ---------------------------------------------------------------------------
DROP PROCEDURE IF EXISTS retry_failed_syncs //

CREATE PROCEDURE retry_failed_syncs()
BEGIN
    UPDATE mdl_yjs_sync_queue 
    SET synced = 0, 
        sync_error = NULL,
        sync_attempts = sync_attempts + 1
    WHERE synced = 2;  -- Failed status
    
    SELECT ROW_COUNT() AS reset_rows;
END //

-- ---------------------------------------------------------------------------
--  PROCEDURE: get_pending_by_table
--  Shows pending changes grouped by table
--  Usage: CALL get_pending_by_table();
-- ---------------------------------------------------------------------------
DROP PROCEDURE IF EXISTS get_pending_by_table //

CREATE PROCEDURE get_pending_by_table()
BEGIN
    SELECT 
        table_name,
        operation,
        COUNT(*) as pending_count
    FROM mdl_yjs_sync_queue
    WHERE synced = 0
    GROUP BY table_name, operation
    ORDER BY table_name, operation;
END //

DELIMITER ;

-- ═══════════════════════════════════════════════════════════════════════════════
--  VERIFICATION
-- ═══════════════════════════════════════════════════════════════════════════════

-- Show all created triggers
SELECT 
    '📋 CREATED TRIGGERS' AS Info;

SELECT 
    TRIGGER_NAME,
    EVENT_MANIPULATION AS Event,
    EVENT_OBJECT_TABLE AS `Table`,
    ACTION_TIMING AS Timing
FROM information_schema.TRIGGERS 
WHERE TRIGGER_SCHEMA = DATABASE()
  AND TRIGGER_NAME LIKE 'sync_%'
ORDER BY EVENT_OBJECT_TABLE, EVENT_MANIPULATION;

-- Show sync queue table structure
SELECT '📋 SYNC QUEUE TABLE STRUCTURE' AS Info;
DESCRIBE mdl_yjs_sync_queue;

-- Display success message
SELECT '═══════════════════════════════════════════════════════════════════' AS '';
SELECT '✅ SYNC TRIGGERS v4.0 CREATED SUCCESSFULLY!' AS Status;
SELECT '═══════════════════════════════════════════════════════════════════' AS '';
SELECT CONCAT('📌 Configured for: ', @VM_NAME) AS Configuration;
SELECT '' AS '';
SELECT '📋 Supported Tables (9 Total):' AS Info;
SELECT '   📚 mdl_course          - Courses' AS '';
SELECT '   📝 mdl_quiz            - Quizzes' AS '';
SELECT '   📄 mdl_assign          - Assignments' AS '';
SELECT '   ❓ mdl_question        - Question Bank' AS '';
SELECT '   📊 mdl_grade_items     - Grade Items' AS '';
SELECT '   🎯 mdl_grade_grades    - Student Grades' AS '';
SELECT '   💬 mdl_forum           - Forums' AS '';
SELECT '   🔧 mdl_course_modules  - Course Modules/Activities' AS '';
SELECT '   👥 mdl_user_enrolments - User Enrollments' AS '';
SELECT '' AS '';
SELECT '⚡ All triggers capture INSERT, UPDATE, DELETE operations' AS '';
SELECT '═══════════════════════════════════════════════════════════════════' AS '';
