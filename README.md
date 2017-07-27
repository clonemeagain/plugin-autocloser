# osTicket - Ticket Closer Plugin

Closes old tickets, so you don't have to! 



## To install
- Download master [zip](https://github.com/clonemeagain/plugin-autocloser/archive/master.zip) and extract into `/include/plugins/autocloser`
- Then Install and enable as per normal osTicket Plugins

## To configure

Visit the Admin-panel, select Manage => Plugins, choose the `Ticket Closer` plugin

## Caveats:

- Assumes osTicket v1.10+ The API changes a bit between versions..

## Admin Options

- Specify how many days old is too many days without being updated, simply enter a number here, and when that many days has passed, the ticket will be closed automatically.
- Set Status: I'm not 100% sure I know exactly which status is which, so, just make sure it will change it to "Closed" (or whatever that is in your language), by specifying it from the dropdown. Also good if you want to change the status to "Resolved" or some other thing.
- Check Frequency: How often do we check the database for old tickets? Default is every 2 hours. 
- Tickets to closer per run: How many tickets could we close if we are to close old open tickets?
- Auto-Note: Admin can specify a note to append to the message thread (Note, not Reply/Message), doesn't get emailed to the User.

## To reset
Simply "Delete" the plugin and add it again, all the config will regenerate from defaults.

