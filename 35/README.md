# Adelia



Dev/tested on php 8.4.1-fpm nginx and ubuntu 24.04 + bcrypt. The standard install of the previous covers all the deps. This does not even need ffmpeg or stuff like that. If you need something, error.txt will show up and tell you. 

This is 1/100th of the size of vichan's codebase and it is a sensible alternative to old, bloated php apps that can not even run on the latest version of php. This is what vichan should have done about 5 years ago. This app is lean and mean- and it vows to NEVER be bloated and fat! 

Adelia is based on Claire, tinyib, vichan and lynxchan. Adelia is designed to be super small in order to feed the code to ai to audit for security and make any changes the user wants. NOTE- Ai will say something like "If this grows bigger, a more structured MVC (or a simpler router + controllers) approach can help." Well, the entire point is to make the codebase so small that it does NOT NEED a MVC pattern or a simpler router + controllers pattern. If u are going to f/w php, a super tiny codebase is a great way to make sure the code can keep up to date with the latest php version. Simplicity is a security measure in itself. As long as the codebase is small and simple, it does not matter so much if this is in a mvc pattern or not, or if it is all lumped into a single file or not. It would run the same. Most new coders are too stupid to realize that like in so many other things... the textbook way is just the start of learning- not the end. Moreover, ANY app that requires lots of php files would be better off coded in rust or go, and that sort of "categorical claim" is valid and logical.   Mvc is a textbook basic- not a religion.  If you are a brainwashed newb who only read one textbook and still want to change the code to a specific model, (like mvc) go for it. Ai can easily do it. (the code will run the same tho)  This app is privacy oriented- never collects or stores ip anywhere. Time/date is not shown on posts, only used for thread bumping. No extra metadata is collected. None. 



//////////////////////////////////////////////////////////////////////////////////
//////////////////////////////////////////////////////////////////////////////////

/////////////////////////////////////////////////////////////////////////////////
///////////////////////////////////////////////////////////////////////////////////

# Version2-

I disabled the "start new thread" link the js for that and to expand images on click is still in the /js dir but not used. I am going to get rid of all of the js - more modern vanilla js or htmx or somthing similar is way less bloated than jquery. If you want to use any of the vichan js scripts as a drop in solution, this will be the last version that you will be able to do so. 


To install bcrypt and deps for this app-

sudo apt install php8.4-common

sudo apt install php8.4-dev build-essential

Bcrypt and required deps should be installed. 



All the security measures of version 1 plus a few more security updates. Secure trip codes implemented. Also, admin pass is hashed, and i told Ai that the security was horrid and to improve it (lol). You see, to push the ai model i am currently working with as far as it can go, you just keep saying security is bad. The point where it starts going in circles is the point where you pushed it as far as it can go!! See info.png in the folder. See hash.png for instructions on how to hash the admin password.  





# version 2 Security Measures Currently Implemented


1) Bcrypt-Hashed Admin Password

The admin password is stored as a bcrypt hash ($admin_password_hash) and verified with password_verify() instead of storing it in plaintext.

2) CSRF Protection

A global CSRF token file (csrf_secret.txt) is generated with random_bytes().
All POST forms include a hidden csrf_token that must match the global token.
The server side checks this match in verify_csrf_token().

3) Session Security

session.cookie_secure = true ensures cookies are only sent over HTTPS.
session_set_cookie_params() sets httponly and samesite, mitigating XSS and CSRF.
session_regenerate_id(true) is called on admin login to prevent session fixation.

4) Input Sanitization

sanitize_input() strips HTML tags, trims whitespace, and enforces maximum length.
Also uses htmlspecialchars() when printing user content, preventing injection of HTML or scripts.

5) Prepared Statements for SQL

All database queries use $pdo->prepare(...) with bound parameters to avoid SQL injection.

6) Stricter File Upload Security
Maximum file size check (5 MB).
Extension check ($allowed_exts).
finfo MIME type check to match extension vs. real MIME.
For images, is_valid_image_gd() ensures the file truly loads as an image.
For .mp4, a placeholder check is_valid_mp4() ensures it’s at least a real file, plus finfo check.

7) Tripcode with Pepper

Users can add ##secret in their name to generate a pseudo-anonymous secure tripcode.
Doesn’t reveal the secret, nor is it stored in plaintext.


8) Math Captcha
Simple addition puzzle to mitigate bot spam.
generate_captcha() on GET; verify_captcha_or_exit() on POST.

9) Minimal Content-Security-Policy (CSP)

default-src 'self' and others, restricting external scripts or iframes.
Also X-Content-Type-Options: nosniff and X-Frame-Options: SAMEORIGIN for extra browser protection.


10) Directory and Board Name Validation

Checking preg_match('/^[a-zA-Z0-9_-]+$/', $board_name) to avoid path traversal or invalid names.

11) Permissions Fix
    
fix_permissions() can recursively set directories to 0755 and files to 0644 via the admin panel or install.php.
This helps ensure malicious uploads do not become executable.

12) Deletion Flow with Admin Password
Deleting boards or posts requires re-verification of the admin password hash.

13) Session Hardening
Strict mode and careful cookie parameters in session_start(); prevents insecure session behaviors.

14) No HTML Formatting Allowed
By default, user input is displayed as text-only (no < b > or < i > etc.), minimizing XSS risk. NOTE- THIS GITHUB README ITSELF DOES NOT ALLOW THE TAGS BUT WHEN I SIMPLY PUT A SPACE AFTER < AND i AND > IT SHOWS UP- an example of how vulnerable front facing web is to xss. Web security for any site on earth is quite absurdly inept, in fact. There are many ways around everything. 





# VERSION2 NOTES
ENTER THE FOLLOWING IN YOUR COMMAND PROMPT TO CHANGE ADMIN PASSWORD HASH :::

php -r "echo password_hash('CHANGEMEEEEE', PASSWORD_BCRYPT).PHP_EOL;" 

then paste the hash in the right area of config.php

Version 2 should be superior to version 1 - so far the testing is holding up fine. SECURITY IS FAR FROM COMPLETE THO... THAT IS THE NATURE OF PHP. 




















/////////////////////////////////////////////////////////////////////////////////////////////////////////
/////////////////////////////////////////////////////////////////////////////////////////////////////////
/////////////////////////////////////////////////////////////////////////////////////////////////////////








# Version1-



Version 1 is a framework- a quick starter kit, a seed which you or ai can grow and shape to any form. It totals in around 1500 lines of code and has the same flow/features as vichan. IF you are stuck on wanting to make this into 200 php files, 300 js files, and a bunch of not necessary options-, use postgreSQL instead of maria db. Postgre is superior for complicated tasks. 

Made for php 8.4.1 and above. Is not meant to run on any lower version of php. Runs as is, but could be refined. It does not call on the js yet to make the images larger on click (i am going to use a more secure js than what shows there).  Is the most basic framework for a working imageboard. 



Below is an overview of the key security features and best-practices implemented:

Strict Typing Mode

Every PHP file includes declare(strict_types=1);.
Functions use explicit argument and return type declarations to help prevent unexpected type coercion bugs.


Secure Session Configuration

Cookie Parameters: session_set_cookie_params() (or equivalent in session_start() options) enforces attributes such as httponly, samesite, and secure (if over HTTPS).
Session Regeneration: On successful admin login, session_regenerate_id(true) is called to prevent session fixation.
Strict Mode: session.use_strict_mode = 1 helps prevent session hijacking by invalidating uninitialized session IDs.


CSRF Protection

A global CSRF token is stored in a server-side file (csrf_secret.txt).
On every form submission, the app includes a hidden csrf_token.
Verification uses hash_equals() to defend against timing attacks.
All POST actions must pass the verify_csrf_token() check before proceeding.


Parameterized Queries (SQL Injection Mitigation)

Database interactions consistently use PDO with prepared statements ($db->prepare(...)) and proper parameter binding.
This helps prevent SQL injection and ensures safer handling of user input.


Input Sanitization

The sanitize_input() function trims whitespace, removes HTML tags, and enforces a maximum length.
Regex checks enforce valid board names (^[a-zA-Z0-9_-]+$).
Other fields are sanitized or escaped before rendering in HTML.


File Upload Security

Limited file size (5 MB max).
Extension whitelisting ($allowed_exts) ensures only specific types (e.g., jpg, png, gif, webp, mp4) are accepted.
MIME-type validation using finfo(FILEINFO_MIME_TYPE).
Files are stored in a dedicated uploads directory, with directory creation set to 0755.
The code includes a recommendation to disallow .php execution in that directory (via server config).


XSS Prevention

HTML escaping: htmlspecialchars() is used for all user-generated output (e.g. $name, $comment), preventing script injection.
nl2br() is used carefully after escaping, preserving line breaks without allowing HTML injection.


Security Headers

X-Content-Type-Options: nosniff blocks MIME-type sniffing.
X-Frame-Options: SAMEORIGIN prevents clickjacking in iframes from other domains.
Content-Security-Policy is set to a restricted default (default-src 'self') and allows images/media from 'self'. In real-world usage, you can refine or expand this CSP.


Error Reporting and Logging

Production-friendly settings: display_errors = 0 and log_errors = 1.
Errors are logged to a secure file (error.log) rather than displayed to users.
This reduces the exposure of sensitive information in production.


Directory Cleanup

When boards are deleted, their corresponding directories are recursively removed (including uploaded files).
The delete_directory() function carefully iterates over subdirectories and files, removing them.

Minimal Attack Surface in Admin

The admin panel is only accessible to logged-in administrators (admin_logged_in).
Admin passwords are currently stored in plain text (for illustration), but the codebase recommends hashing with password_hash() in a real production environment.


Optional Additional Safeguards

The code includes suggestions to serve /uploads/ and /threads/ as static or read-only paths where .php cannot be executed.
For even stronger security, you could integrate rate limiting, captcha, or advanced spam detection.
These combined measures (sessions, CSRF, prepared statements, file validation, careful escaping, and so on) help make the app significantly more robust and ready to be deployed with minimal modifications—especially once you hash the admin password and complete any additional server-level hardening.

ANNNNNND if you are new to web/php know that all the security measures implemeted are basic and no where done being complete. With php, it takes an incredible amount of work to actually secure the app in a reasonable way. Then your server has to have lots of security settings properly done. In the end... because it is php... it is STILL NOT SECURE. There are LOTS of imageboards on git that do not even begin to cover security. With PHP you need about 15 different security measures properly coded- and that is STILL ONLY BASIC SECURITY!! Advanced hackers find php laughable in terms of security. The definition of insanity is thinking your php app is secure. Ask ai about php and you will get a flowery answer full of "political correctness" that hints at how bad php is, but stops short of telling you the truth. Well-known hackers who tell the truth in youtube videos paint a more realistic image. Point blank- if you want the best security, php is laughable. Use Rust instead. It is much easier to secure rust apps because it has SOME GOOD built in security already established so there is less work to do. ONLY use php for informal things, and never be under the delusion that your php app is secure. 










