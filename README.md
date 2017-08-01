# [osTicket](https://github.com/osTicket/) - Ticket Closer Plugin

Automatically closes tickets that haven't been updated in a while.



## To Install
- Download master [zip](https://github.com/clonemeagain/plugin-autocloser/archive/master.zip) and extract into `/include/plugins/autocloser`
- Install by selecting `Add New Plugin` from the Admin Panel => Manage => Plugins page, then select `Install` next to `Ticket Closer`.
- Enable the plugin by selecting the checkbox next to `Ticket Closer`, then select `More` and choose `Enable`, then select `Yes, Do it!`
- Configure by clicking `Ticket Closer` link name in the list of installed plugins.

## To Configure

Visit the Admin-panel, select Manage => Plugins, choose the `Ticket Closer` plugin. 

## Caveats:

- Assumes [osTicket](https://github.com/osTicket/) v1.10+ is installed. The API changes a bit between versions.. Open an issue to support older versions, well, we can make it work for any version that supports plugins.
- Doesn't attempt to close tickets with open Tasks (just like the UI).
- If you configure the AutoReply message, you should also specify: "Only close tickets with an Agent Response", and ideally, set "Claim on Response", because otherwise the Reply sent might fail, it assumes there is an Agent to send the Response from, essentially emulates an agent closing a ticket with a Canned Response.

## Admin Options

- Max open Ticket age in days: Specify how many days is too many with no activity for an open ticket, simply enter a number of days, when that has passed, the ticket will be closed automatically.
- Only close tickets with an Agent Response: Defaults to on, if ticked, it will not consider tickets that haven't been replied to as being old, even if they have no activity for more than Max open Ticket age.
- Only close tickets past expiry date: Defaults to off, however, if you use expiration dates on tickets, and want tickets closed only if they have expired, then this will help, it ignores other settings if a ticket has yet to expire. 
- From Status: Ideally you'd set it to "Open", because the point is to Close tickets by changing status to "Closed", however, if you only want to work with tickets in "Awaiting Reply" or whatever custom status you've created, you can specify it here. 
- To Status: Technically, the plugin is changing the ticket's status, so, you can actually select from the list of available statuses and it will change it to that.. kinda pointless changing to "Open", but it's possible. You might prefer "Resolved" if you use that status. The ticket will still have the event "Closed", because that is what it was programmed to do, if you want something different, [let me know](https://github.com/clonemeagain/plugin-autocloser/issues/new), and I'll add an option.
- Check Frequency: How often do we check the database for old tickets? Default is every 2 hours, can be set to run every time cron is run, or once a year.. [Open an issue](https://github.com/clonemeagain/plugin-autocloser/issues/new) for more options if required. 
- Tickets to close per run: How many tickets could we close if we are to close old open tickets? The maximum per run basically. If you enter 5, then every "Check Frequency" the plugin will attempt to close up to 5 open tickets that have had no activity for Max open Ticket age.
- Auto-Note: Admin can specify a note to append to the message thread (Note, not Reply/Message), doesn't get emailed to the User. Default is: `Auto-closed for being open too long with no updates.`
- Auto-Reply: Admin can specify a message to append to the message thread which will Reply to the end-user, can include normal [Email Template Variables](http://osticket.com/wiki/Email_templates#Variables) like CannedResponses or Email Templates (obviously, only those that relate to a ticket work). 

### To reset settings
Simply "Delete" the plugin and add it again, all the configuration will reset from the defaults.

Defaults as per code:
* Max age of open: 999 days which is a bit more than 2 & 1/2 years. Likely a good default.
* Only close tickets with an Agent Response: True
* Only close tickets past expiry date: False
* From Status: Open
* To Status: Closed
* Check Frequency: "Every 2 Hours"
* Tickets to close per run: "20"
* Auto-Note: "Auto-closed for being open too long with no updates."
* Auto-Message: is in HTML.

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
Change the `FALSE` into `TRUE`. [open an issue](https://github.com/clonemeagain/plugin-autocloser/issues/new) if you would like this as an admin option. Likely you would be using this plugin intentionally and debugging is pretty verbose.
