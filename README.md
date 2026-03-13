SIMPLE WP HELPDESK - DOCUMENTATION

Latest Version: 1.1
Requires at least: WordPress 5.3
Requires PHP: 7.2+

A comprehensive, lightweight, and secure ticketing system built natively for WordPress. Keep your client data entirely on your own server.

1. FEATURE SUMMARY

- Secure Client Portal: Clients can submit, view, and reply to tickets from the front-end without needing a WordPress user account. Access is secured via unique cryptographic email tokens.
- Customizable Workflows: Fully customizable ticket statuses and priority levels to fit your business logic.
- Smart Communications: Highly customizable email templates with dynamic placeholders. 
- File Attachments: Two-way, multi-file uploading for clients and technicians. Includes customizable size limits, secure file type checking, and instant JavaScript validation.
- Internal Notes: Technicians can leave private internal notes on tickets that clients cannot see.
- Automation Engine: Background cron jobs can automatically close resolved tickets after a configured amount of inactivity.
- Data Retention & GDPR: Tools to automatically purge old attachments/tickets, permanently delete specific client data by email, and perform a complete plugin factory reset.
- Robust Anti-Spam: Built-in Honeypot, Google reCAPTCHA v2, and Cloudflare Turnstile integrations.
- Page Builder Friendly: Scoped CSS architecture ensures the frontend UI looks perfect inside Elementor and other modern page builders.

2. INSTALLATION

Installing the plugin via a .zip file is quick and easy through the standard WordPress dashboard.

Prerequisites: Ensure you have the "simple-wp-helpdesk.zip" file saved to your computer.

1. Log in to your WordPress Admin Dashboard (e.g., yoursite.com/wp-admin).
2. On the left-hand menu, navigate to Plugins > Add New.
3. At the top of the screen, click the "Upload Plugin" button.
4. Click "Choose File" (or "Browse") and select the simple-wp-helpdesk.zip file from your computer.
5. Click "Install Now".
6. Once WordPress finishes unpacking the file, click the "Activate Plugin" button.
7. Setup Complete! You will now see a "Tickets" menu item on the left side of your dashboard.

NEXT STEP (FRONTEND SETUP):
To display the helpdesk to your users, create a new WordPress Page (e.g., "Support") and place the following shortcode anywhere on the page:
[submit_ticket]

3. CONFIGURATION

To configure the plugin, navigate to Tickets > Settings in your WordPress dashboard. The settings are divided into six tabs:

TAB 1: GENERAL
- Custom Priorities & Statuses: Define your workflow by entering comma-separated values (e.g., Open, In Progress, Resolved, Closed).
- Default Statuses: Tell the system which status represents a New ticket, a Resolved ticket (which triggers the auto-close timer), a Closed ticket (which disables normal replies), and a Re-Opened ticket.
- Max File Upload Size: Set the maximum Megabyte (MB) limit per file upload.

TAB 2: ASSIGNMENT & ROUTING
- Default Assignee: Select a specific technician from the dropdown. All new tickets will be automatically assigned to this user.
- Fallback Alert Email: If a ticket is unassigned, system notifications (new tickets, client replies) will be sent to this email address instead of the primary Site Admin.

TAB 3: EMAIL TEMPLATES
- Customize the Subject and Body of every email the system sends out.
- Use Placeholders to dynamically insert data. Available placeholders include: {name}, {email}, {ticket_id}, {title}, {status}, {priority}, {message}, {autoclose_days}, {ticket_url} (Frontend link for clients), and {admin_url} (Backend link for technicians).
- Note: If you need to revert a template, click the red "Reset to default" link below the text box.

TAB 4: MESSAGES
- Customize the front-end text displayed to the user when they successfully submit forms, reply to tickets, or trigger errors (like failing the anti-spam check).

TAB 5: ANTI-SPAM
- Method: Choose between None, Honeypot (invisible trap for bots), Google reCAPTCHA v2, or Cloudflare Turnstile.
- If using reCAPTCHA or Turnstile, paste your public Site Key and Secret Key here. The widgets will automatically render on your front-end form.

TAB 6: TOOLS
- Automated Data Retention: Configure background tasks. Set how many days to keep physical file attachments before deleting them to save server space, and how many days to keep entirely inactive tickets before purging them. Set to 0 to disable.
- Uninstallation Behavior: Check this box if you want WordPress to securely wipe all tickets, files, and settings when you delete the plugin.
- Danger Zone: Manual tools to Purge all tickets, Factory Reset the plugin, or perform a GDPR-compliant purge of a specific client's email address and data.

4. USAGE

PART A: FOR CLIENTS / END-USERS

Submitting a Ticket:
1. Navigate to the Support page on the website.
2. Fill out your Name, Email, Priority, Summary (Title), and a detailed Description.
3. Click "Choose Files" to attach any relevant screenshots or documents.
4. Click Submit Ticket. You will receive an email confirmation containing a secure tracking link.

Managing an Existing Ticket:
1. Open the confirmation email and click the secure tracking link.
2. You will be taken to your private ticket portal to see the current status and conversation history.
3. To Reply: Type your message in the reply box, attach files if necessary, and click Send Reply.
4. To Close: If a technician marks the ticket as "Resolved", a blue banner will appear allowing you to officially close the ticket yourself.
5. To Re-open: If a ticket is marked "Closed", the standard reply box is replaced by a "Re-open Ticket" form. Fill it out to alert the technicians that you still need help.

PART B: FOR TECHNICIANS / ADMINS

Viewing and Managing Tickets:
1. Log into the WordPress Dashboard and click Tickets.
2. Click on a ticket's Title to open the Ticket Editor.
3. On the right-hand sidebar (Ticket Details), you will see the client's information, aggregate links to all attached files, and dropdowns to update the Assignee, Priority, and Status.

Communicating with Clients:
1. Scroll down to the Conversation & Reply box. Here you will see the timeline.
2. Public Reply: Type in the left-hand box and attach files. This will be emailed to the client.
3. Internal Note: Type in the yellow right-hand box. This will only be visible to other logged-in technicians. The client will not see this or receive an email.
4. Saving: Once you have typed your reply and/or updated the status dropdowns on the right, scroll up and click the blue "Update" button on the right-hand side.
   (Pro-Tip: If you type a public reply AND change the status to Closed/Resolved simultaneously, the system will smartly combine these actions into a single email notification for the client.)

5. CHANGELOG

VERSION 1.2

- Added: Native GitHub auto-updater. The plugin now securely checks the linked GitHub repository for new releases and serves them directly to the WordPress dashboard, functioning identically to an official repository plugin.
- Optimized: Completely overhauled the background maintenance crons. Tasks (Auto-Close, Ticket Purge, Attachment Purge) are now split into separate staggered events and use strict SQL-level filtering to process tiny micro-batches. This completely eliminates the "cURL error 28" timeout issue on resource-restricted web hosts.
- Optimized: Centralized the default configuration engine into a statically cached object to drastically lower memory usage on every page load and guarantee fallback text if settings are not explicitly saved.
- Security: Enhanced the frontend cryptographic token validation logic, utilizing hash_equals() to protect the client portal against advanced timing attacks.
- Security: Added strict path-traversal prevention to the data retention file unlinking functions.
- Security: Added explicit authorization checks (current_user_can) to the backend save routines to prevent privilege escalation.

VERSION 1.1
- Improved: Elementor Page Builder compatibility. The frontend shortcode now utilizes a completely scoped CSS architecture. This forces internal form elements to remain neatly left-aligned even if the surrounding Elementor column is center-justified. 
- Updated: Modernized the frontend UI with better padding, focus states, badge designs, and alert box styling for a more professional appearance out-of-the-box.
- Updated: Swapped raw inline HTML tags around form inputs for structured DIV groups to prevent theme line-height layout conflicts.

VERSION 1.0 (Initial Release)
- Added: Custom Post Type for native WordPress backend integration.
- Added: Front-end submission form and secure token-based client portal via shortcode.
- Added: Tabbed Admin Settings panel for configuring General Settings, Routing, Emails, Messages, Anti-Spam, and Tools.
- Added: 2-way multi-file upload support with JavaScript size and extension validation.
- Added: Dedicated Technician UI with public reply and private "Internal Note" capabilities.
- Added: Background Automation (Auto-Close inactive resolved tickets, Auto-Purge old tickets/attachments).
- Added: GDPR Client Data Purge tool and deep uninstallation cleanup routines.
- Added: Honeypot, Google reCAPTCHA v2, and Cloudflare Turnstile anti-spam integrations.
- Optimized: Micro-batched cron jobs to prevent server timeouts (cURL error 28).
