# [osTicket](https://github.com/osTicket/) - Ticket Closer Plugin

Automatically closes tickets that haven't been updated in a while.

## Preview of Ticket thread after plugin has run
![screenshot](https://user-images.githubusercontent.com/5077391/29966601-fe0eea4e-8f55-11e7-8b33-090b27d17460.png)

## Caveats/Assumptions:

- Assumes [osTicket](https://github.com/osTicket/) v1.10+ is installed. The API changes a bit between versions.. Open an issue to support older versions.
- Assumes PHP 5.6+ is being used on the server. (Earlier versions will cause crash when plugin enabled)
- Assumes you have cron configured! It works from cron calls, so if you don't have it enabled, you _will_ need to tick the "Autocron" option in the settings. [Guide to osTicket cron](http://osticket.com/wiki/POP3/IMAP_Setting_Guide#Recurring_tasks_scheduler_.28Cron_Job.29)
- The plugin does not attempt to close tickets with open Tasks (same as core).

## Install the plugin
- Download master [zip](https://github.com/clonemeagain/plugin-autocloser/archive/master.zip) and extract into `/include/plugins/autocloser`
- Install by selecting `Add New Plugin` from the Admin Panel => Manage => Plugins page, then select `Install` next to `Ticket Closer`.
- Enable the plugin by selecting the checkbox next to `Ticket Closer`, then select `More` and choose `Enable`, then select `Yes, Do it!`
- Configure by clicking `Ticket Closer` link name in the list of installed plugins.

## Configure the plugin

Visit the Admin-panel, select Manage => Plugins, choose the `Ticket Closer` plugin. 

- Check Frequency: Drop down selector for how often you want the plugin to check the tickets? Default is every 2 hours, can be set to run every time cron is run, or once a year.. [Open an issue](https://github.com/clonemeagain/plugin-autocloser/issues/new) for more options if required. 
- Use Autocron: A checkbox to enable checking via Autocron, (default unchecked). If you don't have a cron config 
- Tickets to process per run per group: How many tickets could we close if we are to close old open tickets? The maximum per run per group basically. If you enter 5, then every "Check Frequency" the plugin will attempt to close up to 5 open tickets that have had no activity for Max open Ticket age, for every enabled group.

### Explanation of Setting Groups

Because you have many ticket status's, why wouldn't you want to be able to change from a status to another status multiple times? Well, maybe you do! :-)
This has a defined limit of 4 setting groups, however, with minor tweaking (before installing) you can set it as high as you like. If you change it after installing, be warned, it will completely mess up the plugin config. Nothing I can do about that, tryer beware.

To change the number of groups, open the plugin directory, find the file `config.php` then change the following line near the top:

```php
const NUMBER_OF_SETTINGS = 4;
```

The only real problem with cranking it up high, is that it will increase the amount of time the admin page takes to load.. also, you have to scroll passed all of them to get to the save button. So, only set it as high as you need. 

### Each Setting Group:

You can associate a status change with a Canned Reply, (or no reply), giving you control over many status changes automatically.


- Enable Group: Tick this to enable the settings group, default is only the first group is enabled. 
- Groupname: Name the group something, if you have debugging enabled, it adds this name to the logs, otherwise, it only ever shows this name in the configuration page. Completely optional.
- Max open Ticket age in days: Specify how many days is too many with no activity for an these tickets, simply enter a number of days, when that has passed, the ticket status will be changed automatically.
- Only change tickets with an Agent Response: Defaults to checked. (Agent response is a message posted by an agent that the User would have received, if none of these have been posted, and this box is checked, then the tickets will stay in their current status).
- Only change tickets past expiry date: Defaults to off, however, if you use expiration dates on tickets, and want tickets changed only if they have expired, then this will help, it ignores other settings if a ticket has yet to expire. 
- From Status: Initially set to "Open", because the original plan was to Close tickets by changing status to "Closed", however, if you want to change tickets in another status you've created, you can specify it here. 
- To Status: You can select from the list of available statuses and it will change it to that. kinda pointless changing to "Open", but it's possible. You might prefer "Resolved" if you use that status.
- Auto-Note: Admin can specify a note to append to the message thread (Note, not Reply/Message), doesn't get emailed to the User. Default is: `Auto-closed for being open too long with no updates.`
- Robot Account: Default is "ONLY Send as Ticket's Assigned Agent", if there isn't an assigned agent, an error message will be posted to the thread as a Note (not visible to User). However, the real fun begins when you pick someone to act as the Robot. You can create an agent account with any name/settings you like, even disable it, and then select it from this list to act on your behalf as the ticket-closer. If you don't select someone, it will send and close as if the assigned staff member did it. If a ticket is assigned to a team not an agent, it treats it like nobody is assigned.
- Auto-Reply Canned Response: Admin can specify a message to append to the message thread which will Reply to the end-user, can include normal [Email Template Variables](http://osticket.com/wiki/Email_templates#Variables) like other CannedResponses or Email Templates (obviously, only those that relate to a ticket work). Additional variables are available (more if you ask nice!) `%{lastresponse}` or `%{firstresponse}` even `%{wholethread}` to inject the entire client visible message thread!
If you select an Auto-Reply canned response, you should also specify: "Only close tickets with an Agent Response", and ideally, set "Claim on Response", because otherwise the Reply sent might fail, it assumes there is an Agent to send the Response from, essentially emulates an agent closing a ticket with a Canned Response. Alternatively: You can select a Robot Agent, and configure that to close tickets for you. Simply select the Agent in the admin panel. 
The canned responses are normal canned responses, configurable by anyone with a role that has permissions to edit the `/scp/canned.php` page. (Configure via Agents -> Roles -> Select Role -> Permissions -> Knowledgebase)


### To reset the config
Simply "Delete" the plugin and install it again, all the configuration will reset from the defaults.
Admin panel -> Manage -> Plugins, slect the checkbox next to `Ticket Closer` then, from the drop-down select "Delete", then "Yes, Do it!" from the popup. It's not actually deleting the plugin, just it's config. 
Then go through the "Add New Plugin" process again.

### To enable extra logging
Open the `class.CloserPlugin.php` file, find: 
```php
    /**
     * Set to TRUE to enable extra logging.
     *
     * @var boolean
     */
    const DEBUG = FALSE;
```
Change the `FALSE` into `TRUE`. 


Enjoy!
