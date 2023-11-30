<?php return array(
 'id' => 'clonemeagain:autocloser', # notrans
 'version' => '3.1.1',
 'name' => 'Ticket Closer',
 'author' => 'clonemeagain@gmail.com',
 'description' => 'Changes ticket statuses based on age.',
 'url' => 'https://github.com/clonemeagain/osticket-plugin-closer',
 'plugin' => 'class.CloserPlugin.php:CloserPlugin',
 'ost_version' =>    '1.17', # Require osTicket v1.17	
);

/*
CHANGELOG
---------
# Changelog
All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
