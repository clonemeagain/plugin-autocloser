# osTicket - Ticket Rewriter Plugin

Enables rewriting of email messages before a new ticket is created. 



## To install
- Download master [zip](https://github.com/clonemeagain/plugin-fwd-rewriter/archive/master.zip) and extract into `/include/plugins/rewriter`
- Then Install and enable as per normal osTicket Plugins

## To configure

Visit the Admin-panel, select Manage => Plugins, choose the `Ticket Rewriter` plugin

## Caveats:

- Only works on Emails!
- Assumes English.. Sorry, I don't speak enough of even one foreign language to write regex for them. Let me know if you've any ideas!
- If you require user login, it won't rewrite a ticket to a non-existent user. Only tested with Registration disabled!
- Assumes osTicket v1.10+ The API changes a bit between versions..

## Admin Options

### Email Forwarding rewriter configuration:
Ensures that the original sender of a message is preserved in the ticket metadata, and allows replies to go back to them.

- Admin options allow you to specify which domains are allowed to be rewritten (ie, enter your company domain). 
- Admin option to parse/rewrite messages from Drupal Contact forms
- Admin option to enable logging of actions into the osTicket admin logs

I suggest at least your "domain name", otherwise the forwarding detector will ignore all forwarded mail.

To start, you should probably enable logging. You can disable when you're done testing. (While code is prerelease, DEBUG has been left on, so you can see many log entries in your webserver logs, simply change that to FALSE in class.RewriterPlugin.php to stop them). 

### Drupal Contact Parser
If you use Drupal on any external websites, and don't use an API to talk to osTickets (ie, the Contact form simply emails your ticket system), you can use the Drupal Contact Parser to rewrite those inbound emails back into the original senders, so tickets are as if they were created by the original sender.  
  

## Extra Power Admin Settings
Some dangerous settings have been added, allowing the admin to define find & replace patterns in both subjects, emails and message bodies for incoming emails. The regex is particularly flexible.. includes $1 backref's for matched groups etc. 

Some interesting use-cases would be good, if you've found a use for it, submit a pull request for the readme to add them here:

### Email Replacements (case insensitive, applies to email address itself)
- internal.domain.lan:domain.tld
- devicename@internal.domain.lan:it.department@domain.tld

### Text Replacements (case insensitive, applies to subject and body only)
- the customer is always right:I don't care
- cheque is in the mail:<b>I'm a tool</b>
- failure:Success (make complaint tickets entertaining: "I am not happy with this product's absolute Success!" 

### Regex Text Replacements (validated raw php regex, applies to everything we can, including mail headers)
- /cloud/i:magic
- /([\w\d]+)\.internal\.lan/i:$1


