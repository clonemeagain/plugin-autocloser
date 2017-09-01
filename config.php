<?php
require_once INCLUDE_DIR . 'class.plugin.php';
require_once INCLUDE_DIR . 'class.message.php';
class CloserPluginConfig extends PluginConfig {
	
	/**
	 * How many groups of settings we support.
	 * Note: Changing this is a matter of uninstalling, changing, then reinstalling.
	 * Sounds stupid, but no, if you try and change it live, you risk losing all
	 * plugin settings. Not kidding. Tried to make it dynamic, doesn't work.
	 *
	 * @var integer
	 */
	const NUMBER_OF_SETTINGS = 4;
	
	// Provide compatibility function for versions of osTicket prior to
	// translation support (v1.9.4)
	function translate() {
		if (! method_exists ( 'Plugin', 'translate' )) {
			return array (
					function ($x) {
						return $x;
					},
					function ($x, $y, $n) {
						return $n != 1 ? $y : $x;
					} 
			);
		}
		return Plugin::translate ( 'closer' );
	}
	
	/**
	 * Build an Admin settings page.
	 *
	 * {@inheritdoc}
	 *
	 * @see PluginConfig::getOptions()
	 */
	function getOptions() {
		list ( $__, $_N ) = self::translate ();
		
		// I'm not 100% sure that closed status has id 3 for everyone.
		// Let's just get all available Statuses and show a selectbox:
		static $staff, $statuses = array ();
		
		// Doesn't appear to be a TicketStatus list that I want to use..
		if (! $statuses) {
			foreach ( TicketStatus::objects ()->values_flat ( 'id', 'name' ) as $s ) {
				list ( $id, $name ) = $s;
				$statuses [$id] = $name;
			}
			$staff [0] = $__ ( 'ONLY Send as Ticket\'s Assigned Staff' );
			foreach ( Staff::objects () as $s ) {
				$staff [$s->getId ()] = $s->getName ();
			}
		}

		$global_settings = array (
				'global' => new SectionBreakField ( array (
						'label' => $__ ( 'Global Config' ),
				) ),
				
				'frequency' => new ChoiceField ( array (
						'label' => $__ ( 'Check Frequency' ),
						'choices' => array (
								'0' => $__ ( 'Every Cron' ),
								'1' => $__ ( 'Every Hour' ),
								'2' => $__ ( 'Every 2 Hours' ),
								'6' => $__ ( 'Every 6 Hours' ),
								'12' => $__ ( 'Every 12 Hours' ),
								'24' => $__ ( 'Every 1 Day' ),
								'36' => $__ ( 'Every 36 Hours' ),
								'48' => $__ ( 'Every 2 Days' ),
								'72' => $__ ( 'Every 72 Hours' ),
								'168' => $__ ( 'Every Week' ), // This is how much banked Annual Leave I have in my day-job.. noice
								'730' => $__ ( 'Every Month' ),
								'8760' => $__ ( 'Every Year' ) 
						),
						'default' => '2',
						'hint' => $__ ( "How often should we run?" ) 
				) ),
				'use_autocron' => new BooleanField ( array (
						'label' => $__ ( 'Use Autocron' ),
						'default' => 0,
						'hint' => $__ ( 'If you only have auto-cron, you will want this on.' ) 
				) ),
				'purge-num' => new TextboxField ( array (
						'label' => $__ ( 'Tickets to close per run per group' ),
						'hint' => $__ ( "How many tickets should we close each time for each group? (small for auto-cron)" ),
						'default' => 20 
				) ),
				'robot-account' => new ChoiceField ( array (
						'label' => $__ ( 'Robot Account' ),
						'choices' => $staff,
						'default' => 0,
						'hint' => $__ ( 'Select account for sending replies, account can be locked, still works.' ) 
				) ) 
		);
		
		// Configure groups to associate a status change with a canned response notification:
		// Get all the canned responses to use as selections:
		$responses = Canned::getCannedResponses ();
		
		// Build an array of group configurations:
		$canned_to_status_groups = array ();
		foreach ( range ( 1, self::NUMBER_OF_SETTINGS ) as $i ) {
			$gn = $this->get ( 'group-name-' . $i );
			$gn = $gn ? ': ' . $gn : '';
			$canned_to_status_groups [] = array (
					'group' . $i => new SectionBreakField ( array (
							'label' => $__ ( 'Group ' . $i . $gn ) 
					) ),
					'group-enabled-' . $i => new BooleanField ( array (
							'label' => __ ( 'Enable Group' ),
							'hint' => __ ( 'Groups can be configured without being enabled. ' ),
							'default' => ($i == 1) ? TRUE : FALSE 
					) ), // Enable first group by default.
					'group-name-' . $i => new TextboxField ( array (
							'label' => 'Groupname',
							'hint' => $__ ( 'Name this group' ) 
					) ),
					'purge-age-' . $i => new TextboxField ( array (
							'default' => '999',
							'label' => $__ ( 'Max Ticket age in days' ),
							'hint' => $__ ( 'Tickets with no updates in this many days will match and have their status changed.' ),
							'size' => 5,
							'length' => 4 
					) ),
					'close-only-answered-' . $i => new BooleanField ( array (
							'default' => TRUE,
							'label' => $__ ( 'Only change tickets with an Agent Response' ),
							'hint' => '' 
					) ),
					'close-only-overdue-' . $i => new BooleanField ( array (
							'default' => FALSE,
							'label' => $__ ( 'Only change tickets past expiry date' ),
							'hint' => $__ ( 'Default ignores expiry' ) 
					) ),
					'from-status-' . $i => new ChoiceField ( array (
							'label' => $__ ( 'From Status' ),
							'choices' => $statuses,
							'default' => 1,
							'hint' => $__ ( 'When we "change" the ticket, what are we changing the status from? Default is "Open"' ) 
					) ),
					'to-status-' . $i => new ChoiceField ( array (
							'label' => $__ ( 'To Status' ),
							'choices' => $statuses,
							'default' => 3, // 3 == Open on mine.
							'hint' => $__ ( 'When we "change" the ticket, what are we changing the status to? Default is "Closed"' ) 
					) ),
					
					'admin-note-' . $i => new TextareaField ( array (
							'label' => $__ ( 'Auto-Note' ),
							'hint' => $__ ( 'Create\'s an admin note just before closing.' ),
							'default' => 'Auto-closed for being open too long with no updates.',
							'configuration' => array (
									'html' => FALSE,
									'size' => 40,
									'length' => 256 
							) 
					) ),
					'admin-reply-' . $i => new ChoiceField ( array (
							'label' => $__ ( 'Auto-Reply Canned Response' ),
							'hint' => $__ ( 'Select a canned response to use as a reply just before closing (can use Variables), configure in /scp/canned.php' ),
							'choices' => $responses 
					) ) 
			);
		}
		
		// Merge all the group configurations after the global settings array and return as our config Options Array:
		return array_merge ( $global_settings, ...$canned_to_status_groups );
	}
}