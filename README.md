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

## Admin Options

- Max open Ticket age in days: Specify how many days is too many with no activity for an open ticket, simply enter a number of days, when that has passed, the ticket will be closed automatically.
- Set Status: Technically, the plugin is changing the ticket's status, so, you can actually select from the list of available statuses and it will change it to that.. kinda pointless changing to "Open", but it's possible. You might prefer "Resolved" if you use that status.
- Check Frequency: How often do we check the database for old tickets? Default is every 2 hours, can be set to run every time cron is run, or once a year.. [Open an issue](https://github.com/clonemeagain/plugin-autocloser/issues/new) for more options if required. 
- Tickets to close per run: How many tickets could we close if we are to close old open tickets? The maximum per run basically. If you enter 5, then every "Check Frequency" the plugin will attempt to close up to 5 open tickets that have had no activity for Max open Ticket age.
- Auto-Note: Admin can specify a note to append to the message thread (Note, not Reply/Message), doesn't get emailed to the User. Default is: `Auto-closed for being open too long with no updates.`

### To reset settings
Simply "Delete" the plugin and add it again, all the configuration will reset from the defaults.

Defaults as per code:
* Max age of open: 999 days which is a bit more than 2 & 1/2 years. Likely a good default.
* Set Status: "Closed"
* Check Frequency: "Every 2 Hours"
* Tickets to close per run: "20"
* Auto-Note: "Auto-closed for being open too long with no updates."

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
