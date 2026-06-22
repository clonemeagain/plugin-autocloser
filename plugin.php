<?php
// load translations
list($__, $_N) = Plugin::translate('closer');

return array(
 'id' => 'clonemeagain:autocloser', # notrans
 'version' => '3.4.0',
 'name' => /* trans */ $__('Ticket Closer'),
 'author' => 'clonemeagain@gmail.com / jens@isohd.net',
 'description' => /* trans */ $__('Changes ticket statuses based on age.'),
 'url' => 'https://github.com/JensEB/plugin-autocloser',
 'plugin' => 'class.CloserPlugin.php:CloserPlugin',
 'ost_version' => '1.17', # Require osTicket v1.17
);

/*
CHANGELOG
---------
# Changelog
All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [3.4.0] - 2026-06-03
### Added
- now it's possible to select ticket's create date as calculate from date
- now it's possible to select a fixed day of Month to trigger this instance for execution
- config pre-save checks
  -> purge-num must be a positive integer
  -> The target status must not be included in the status filter list
### Fixed
- some cleaning and hardening tasks
  -> bootstrap() - remove unused $instance var
  -> cron run: ensure, admin-reply is an integer
               make robot-account var save
  -> post_reply() - make $thisstaff changes temporary
  -> render_thread_entry() make correct html

## [3.3.4] - 2026-04-30
### Added
- now it's possible to send a reply as SYSTEM
### Fixed
- set debug vars vor every instance

## [3.3.3] - 2026-03-05
### Fixed
 - force updating lastupdate date, if tickets were changed

## [3.3.2] - 2026-01-20
### Fixed
 - setting "select only overdue tickets" checks now following criterias:
   is overdue OR duedate is set and in the past OR duedate not set and est_duedate set and in the past

## [3.3.1] - 2025-12-16
### Fixed
- log correct note, if ticket isn't closeable with its cause
- send admin-note and admin-reply only, if status ist set
- use status name in notes instead of status state
- make some logical changes in ticket processing
### Added
- translate texts for class.CloserPlugin.php
- config: add hint for answered flag and overdue flag
- extend debug messages

## [3.3.0] - 2025-12-02
### Added
- Ability to select calculate date (from lastupdate, lastmessage or lastresponse)

## [3.2.1] - 2025-12-02
### Fixed
- get ticket_ids for processing: use correct variable for purge-age

## [3.2.0] - 2025-11-14
### Fixed
- use osTickets native function to change ticket status
- configuration pre-check for robot-account - only needed, if an admin-reply is set

### Added
- add language support an translation into german
- allow more than one from-status
- add department filter (only tickets with or without selected depts)
- add help topic filter (only tickets with or without selected topics)

### Removed
- configuration pre-check for admin-reply - no need to check it...

## [3.1.1] - 2023-01-15
### Added
- Changed an $ost->logWarning into $this->LOG[] which I missed earlier.

## [3.1.0] - 2023-01-15
### Added
- Consolidate messages and log to osTicket syslog once per run, 
  instead of multiple times per run.

### Fixed
- Removed print commands to avoid sending output back to CRON caller.

## [3.0.0] - 2023-01-11
### Added
- Compatibility with osTicket 1.17.
- Multi-instance capability.
- Send log entries to osTicket syslog instead of to web server's error log (error_log).

### Removed
- Multiple config options removed from previous version (use instances instead)

## [2.1.0] - 2019-10-13
### Fixed
- Forked from original github version.

*/
