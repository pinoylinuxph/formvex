FORMVEX
Project Brief, Agreed Scope, Architecture, and Development Roadmap
=================================================================

Project Name: Formvex
Project Type: Self-hosted form processing and email delivery engine
Initial Target: Static HTML websites
Primary Backend: Node.js
Development Approach: Standalone MVP first, centralized API second
Current Status: Concept and architecture planning

This document consolidates what we discussed and agreed upon for the Formvex project.


1. PROJECT BACKGROUND
---------------------
You currently maintain multiple HTML websites that contain contact forms.

These forms typically have fields such as:
- Full Name
- Email Address
- Company
- Subject
- Message
- Other website-specific fields

Each form has a submission button that visitors click after completing the required fields.

Static HTML alone cannot process form submissions and deliver messages through an SMTP server. Developing a separate email-processing backend for every website would introduce repetitive development and maintenance work.

You want to create a reusable module that can be installed on existing HTML websites, identify their forms and fields, validate submissions, compose emails, and deliver messages using configurable SMTP settings.

This is the problem Formvex will address.


2. PROJECT IDENTITY
-------------------
After considering several names, we agreed on Formvex.

The name was developed from concepts related to form, vector, exchange, information transmission, and message delivery.

Project: Formvex
Full Product Description: Form Processing and Email Delivery Engine
Tagline: Form Processing. Email Delivery. Simplified.
Category: Website form processing infrastructure
Deployment: Self-hosted
Frontend Compatibility: Static HTML websites
Primary Technology: JavaScript and Node.js
Future Direction: Centralized form processing platform

The name accommodates both the standalone application and the future centralized platform.


3. PRIMARY PROJECT OBJECTIVE
----------------------------
Create a reusable form-processing engine that can be installed on existing HTML websites without requiring extensive modifications to their original code.

Formvex will provide the backend functionality required to process and deliver contact form submissions.

Expected administrator workflow:
1. Install Formvex on the website's hosting environment.
2. Configure the SMTP connection.
3. Identify the website's contact form.
4. Define required fields or use existing HTML validation attributes.
5. Configure the receiving email address.
6. Connect the contact form to Formvex.
7. Test the form submission.
8. Begin receiving email messages.

The existing website's layout, styling, and form design should remain intact. The website should not need to be redesigned or rebuilt to use Formvex.


4. AGREED DEVELOPMENT STRATEGY
------------------------------
V1.0 - Formvex Standalone:
Install a reusable form processing backend independently on each website.

V1.1 - Formvex Standalone Improvements:
Improve deployment, configuration, logging, and security.

V2.0 - Formvex Hub:
Introduce a centralized Formvex API supporting multiple websites.

V2.1 - Formvex Console:
Introduce centralized web-based administration and management.

Architectural principle: Formvex V1.0 must be designed so that its core processing engine can be reused in V2.0 without a complete application rewrite. Separate the frontend client, processing engine, and backend server from the beginning.


5. VERSION 1.0: FORMVEX STANDALONE
---------------------------------
5.1 Deployment Model

Each website will have its own Formvex installation:

SERVER A
|-- Website A
|   |-- HTML
|   |-- CSS
|   `-- JavaScript
`-- Formvex Standalone
    |-- Configuration A
    |-- SMTP A
    `-- Email Recipient A

SERVER B
|-- Website B
|   |-- HTML
|   |-- CSS
|   `-- JavaScript
`-- Formvex Standalone
    |-- Configuration B
    |-- SMTP B
    `-- Email Recipient B

Each installation operates independently on potentially different servers or hosting providers.

Advantages:
- Independent SMTP configuration and website settings.
- Independent backend operation.
- No dependency on a centralized Formvex server.
- Easier troubleshooting and isolation.
- A failure in one installation does not directly affect others.

Trade-off accepted for the MVP: multiple installations must be maintained and updated.


6. FORMVEX V1.0 FUNCTIONAL REQUIREMENTS
---------------------------------------
6.1 Form Detection

Formvex should identify the intended contact form in an existing HTML document, including forms such as:

<form id="contactForm">
<form id="inquiry">
<form class="contact-us-form">

The JavaScript client should support common identification methods and an explicit marker where automatic detection is uncertain:

<form data-formvex>

Alternatively, identify the form through the script integration:

<script
    src="/formvex/formvex.js"
    data-form="#contactForm"
    data-api="/formvex/api/submit">
</script>

Formvex should process only the intended form, not unrelated login, search, newsletter, or registration forms.

6.2 Automatic Field Detection

Support these HTML controls in V1.0:
- Text input
- Email input
- Telephone input
- Textarea
- Select dropdown
- Radio button
- Checkbox

File attachments are deferred to a later version.

Example:

<form id="contactForm">
    <input type="text" name="fullname" required>
    <input type="email" name="email" required>
    <input type="text" name="company">
    <textarea name="message" required></textarea>
    <button type="submit">Send Message</button>
</form>

Formvex should recognize these controls without custom JavaScript per field. Field names also help generate email content.

6.3 Field Validation

Method A: Recognize native HTML attributes such as required and type="email".

Method B: Configure validation rules:

{
  "form": {
    "selector": "#contactForm",
    "required_fields": ["fullname", "email", "message"]
  }
}

Validation requirements:
- Reject missing or empty required fields.
- Reject invalid email formats.
- Enforce configured maximum input lengths.
- Permit empty optional fields.
- Reject submissions violating configured rules.
- Validate again on the backend before delivery.

Frontend validation improves usability. Backend validation protects against direct API requests that bypass browser-side checks.


7. EMAIL COMPOSITION
--------------------
Formvex should dynamically compose an email from submitted fields without a custom email script for each website.

Example submission:

Full Name: Juan Dela Cruz
Email: juan@example.com
Company: ABC Corporation
Service: Server Management
Message: I would like to inquire about your server management services.

Example generated email:

Subject: New Website Contact Form Submission

New contact form submission received.

Full Name:
Juan Dela Cruz

Email:
juan@example.com

Company:
ABC Corporation

Service:
Server Management

Message:
I would like to inquire about your server management services.

Requirements:
- Automatic field extraction and human-readable labels.
- Dynamic message composition.
- Configurable subject, recipient, sender identity, and Reply-To.
- Plain-text and HTML email.
- Reply-To mapped to a validated form email field.

The server must control the recipient and SMTP sender. Visitors must not choose arbitrary delivery addresses.


8. SMTP CONFIGURATION
---------------------
Each installation should support independent SMTP settings.

Example server-side .env:

SMTP_HOST=mail.example.com
SMTP_PORT=587
SMTP_SECURE=false
SMTP_USER=contact@example.com
SMTP_PASSWORD=your-smtp-password
MAIL_FROM=contact@example.com
MAIL_FROM_NAME=Website Contact Form
MAIL_TO=admin@example.com

V1.0 capabilities:
- Custom SMTP host and port.
- SMTP authentication.
- STARTTLS and implicit TLS.
- Configurable sender, recipient, and Reply-To.
- SMTP connection testing.

Multiple SMTP accounts per website are deferred.

SMTP credentials must remain server-side and must never appear in publicly accessible HTML or JavaScript.


9. SUBMISSION HANDLING
----------------------
Expected flow:

VISITOR FILLS CONTACT FORM
           |
           v
VISITOR CLICKS SUBMIT
           |
           v
FORMVEX CLIENT INTERCEPTS SUBMISSION
           |
           v
DETECT AND COLLECT FORM FIELDS
           |
           v
FRONTEND VALIDATION
           |
           v
SUBMIT TO FORMVEX API
           |
           v
BACKEND VALIDATION
           |
           v
SECURITY CHECKS
           |
           v
EMAIL COMPOSITION
           |
           v
SMTP DELIVERY
           |
           v
RETURN SUBMISSION RESULT
           |
           v
DISPLAY SUCCESS OR ERROR MESSAGE

The submit button should temporarily disable during processing to reduce accidental duplicates.

Configurable sample messages:
- Success: Your message has been sent successfully.
- Validation: Please complete all required fields.
- Delivery failure: Unable to send your message. Please try again later.


10. FORMVEX V1.0 TECHNICAL ARCHITECTURE
--------------------------------------
10.1 Formvex Client
Browser-side, framework-independent Vanilla JavaScript.

Responsibilities:
- Detect the designated form.
- Identify fields, names, and values.
- Apply frontend validation.
- Intercept submission and send data to the API.
- Display submission status.

No React, Vue, jQuery, or other frontend framework should be required.

10.2 Formvex Core
Reusable processing engine.

Responsibilities:
- Apply trusted server-side validation rules.
- Process submitted form data.
- Compose email content.
- Handle SMTP delivery.
- Generate processing results.

Core logic should not depend on a site's HTML structure. It will eventually power Formvex Hub.

10.3 Formvex Server
HTTP API and installation-specific configuration layer.

Responsibilities:
- Receive submissions.
- Load server-side configuration.
- Execute security checks.
- Pass submissions to Formvex Core.
- Return API responses and generate logs.

The HTTP server should remain separate from the core processing logic.


11. PROPOSED TECHNOLOGY STACK
-----------------------------
Frontend Integration: Vanilla JavaScript
Backend Runtime: Node.js
API Framework: Fastify
Email Delivery: Nodemailer
Validation: JSON Schema and Ajv
Configuration: JSON and environment variables
Logging: Pino
Reverse Proxy: Nginx
Deployment: Node.js service or Docker

These are proposed technologies, not completed implementation decisions. Formvex should not depend on one hosting provider.

Documentation:
https://nodejs.org/
https://fastify.dev/
https://nodemailer.com/
https://ajv.js.org/
https://getpino.io/
https://nginx.org/


12. PROPOSED REPOSITORY STRUCTURE
---------------------------------
formvex/
|-- client/
|   `-- formvex.js
|-- core/
|   |-- validator.js
|   |-- composer.js
|   |-- mailer.js
|   `-- processor.js
|-- server/
|   |-- app.js
|   |-- routes/
|   `-- middleware/
|-- config/
|   |-- formvex.json
|   `-- .env.example
|-- docs/
|-- tests/
|-- package.json
`-- README.md

The exact structure remains subject to finalization. Form detection belongs in the browser-side client, while trusted validation belongs in the core.


13. CONFIGURATION-DRIVEN DESIGN
-------------------------------
Use per-website configuration, not source-code modifications, to control behavior.

Illustrative configuration (not finalized):

{
  "site": {
    "name": "Company Website",
    "domain": "example.com"
  },
  "form": {
    "selector": "#contactForm",
    "required_fields": ["fullname", "email", "message"]
  },
  "email": {
    "subject": "New Contact Form Submission",
    "recipient": "sales@example.com",
    "reply_to_field": "email"
  },
  "messages": {
    "success": "Your message has been sent successfully.",
    "error": "Unable to send your message.",
    "validation": "Please complete all required fields."
  }
}

Sensitive information, recipient addresses, and trusted validation rules must remain on the backend, not in a publicly served configuration file.


14. SECURITY REQUIREMENTS
-------------------------
Security belongs in the MVP because public forms accept untrusted input.

Controls:
- Server-side validation against trusted rules.
- Rate limiting.
- Honeypot field for some automated submissions.
- Allowed-origin enforcement for supported browser origins.
- Input length limits.
- SMTP credential protection.
- Server-controlled recipients to prevent arbitrary delivery.
- Logging of submission outcomes and errors.
- HTTPS for browser-to-backend communication.

Origin and Referer headers alone do not authenticate clients. Also enforce rate limits, trusted configuration, and strict recipient controls.

Formvex must not become an open email relay.


15. INSTALLATION EXPERIENCE
---------------------------
Desired administrator experience:

Step 1: Install backend dependencies.

npm install

Step 2: Create environment configuration.

cp .env.example .env

Configure SMTP and site settings.

Step 3: Identify the existing form.

<form id="contactForm">

Step 4: Load the client.

<script
    src="/formvex/formvex.js"
    data-form="#contactForm"
    data-api="/formvex/api/submit">
</script>

Step 5: Start backend.

npm start

Step 6: Submit a test form and confirm that the configured recipient receives the email.

These are illustrative commands and paths, not commands from an implemented release. Private backend files, .env, and logs must not be placed in the publicly accessible web directory.


16. VERSION 1.1: STANDALONE IMPROVEMENTS
---------------------------------------
Potential improvements after the MVP:
- Configuration validation.
- Improved spam protection.
- Improved logging.
- Deployment and update tooling.
- SMTP diagnostics.
- Better installation documentation.

The exact V1.1 scope remains open.


17. VERSION 2.0: FORMVEX HUB
----------------------------
Introduce a centralized API serving multiple websites.

WEBSITE A ----\
WEBSITE B -----+----> FORMVEX HUB
WEBSITE C ----/            |
                          v
                  SITE IDENTIFICATION
                          |
                          v
                  SITE CONFIGURATION
                          |
                          v
                    FORMVEX CORE
                          |
                          v
                   SMTP TRANSPORT
                          |
                          v
                   EMAIL RECIPIENT

V2.0 objectives:
- Multiple registered websites.
- Independent SMTP settings and recipients per website.
- Independent field validation rules.
- Centralized logging and security controls.
- Site identification and configuration management.
- Management of multiple form configurations.

V1 architecture should enable reuse of the frontend client and core. V2 details will be specified after the standalone architecture is established.


18. VERSION 2.1: FORMVEX CONSOLE
--------------------------------
Proposed browser-based administration interface.

Potential capabilities:
- Register and configure websites.
- Manage and test SMTP accounts.
- Assign form fields and validation rules.
- Configure email recipients and subjects.
- Review submission activity and errors.
- Configure spam protection and rate limits.

These are future concepts, not part of the MVP.


19. CONSOLIDATED V1.0 MVP SCOPE
-------------------------------
Included:
- Standalone installation per website.
- Vanilla JavaScript client.
- Compatibility with existing HTML forms.
- Form identification and automatic field detection.
- Required field assignment.
- Frontend and backend validation.
- Dynamic email composition.
- SMTP configuration and authentication.
- Configurable recipient, subject, and Reply-To.
- HTML and plain-text email.
- Submission success and error messages.
- Rate limiting and honeypot protection.
- Server-side logging.
- SMTP connection testing.

Deferred:
- File attachments.
- Multiple SMTP accounts per website.
- Submission database.
- Visual form builder.
- Centralized API (V2.0).
- Web administration interface (V2.1).


20. IMPORTANT ARCHITECTURAL PRINCIPLES
--------------------------------------
Reusability: The same engine should work across multiple HTML websites.

Minimal Integration: Existing websites should require minimal code changes.

Independent Deployment: V1 must work independently on each website.

Configuration-Driven: Site-specific behavior should be configurable.

Core Separation: Processing should remain separate from HTTP transport and frontend integration.

Security: Public submissions are untrusted input.

Future Compatibility: V1 should support migration to centralized processing.

Framework Independence: The frontend client should not require a framework.


21. DECISIONS STILL PENDING
---------------------------
- Final configuration schema.
- Exact API request and response formats.
- Form auto-detection rules.
- Field-label extraction rules.
- Handling multiple contact forms on one website.
- Email HTML template.
- SMTP retry behavior.
- Logging format and retention.
- Rate limiting thresholds.
- Supported Node.js versions.
- Minimum hosting requirements.
- Whether Docker support ships in the initial release.
- Software license.
- Open-source release strategy.

These will be resolved during technical specification and do not change the agreed product direction.


22. NEXT DEVELOPMENT MILESTONE
------------------------------
Prepare the "Formvex V1.0 Software Requirements and Technical Architecture" specification.

It should define:
1. Functional and non-functional requirements.
2. System architecture and component responsibilities.
3. Form detection and field mapping.
4. Validation behavior and configuration rules.
5. SMTP integration and email composition.
6. API endpoints and request/response formats.
7. Security requirements.
8. Installation and deployment procedures.
9. Configuration file structure.
10. Error handling and logging.
11. Testing and acceptance criteria.
12. Version 1.0 development milestones.


FINAL PROJECT DIRECTION
-----------------------
FORMVEX
Form Processing. Email Delivery. Simplified.

Build a reusable, self-hosted form processing and email delivery engine for existing HTML websites.

V1.0 allows independent installation on each website, connecting an existing contact form, configuring SMTP, identifying and validating fields, and automatically delivering submissions through email.

Three primary components:
Formvex Client + Formvex Core + Formvex Server.

V2.0 introduces Formvex Hub for multiple websites using a centralized backend and the core developed for V1.

V2.1 introduces Formvex Console for browser-based administration.

Immediate development target: Formvex Standalone V1.0.

