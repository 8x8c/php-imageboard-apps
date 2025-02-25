# Claire modernized PHP 8.4.1   version 001



This is a fork of https://github.com/ClaireIsAlive/Claire the original is over 9 years old. AI got good enough where one can feed the whole claire code to it and get it running. Times have changed tho, and some features that shined 9 years ago are no longer viable. In this fork i eliminated over 2/3 of the code base, and added lots of modern security features.

to do- i will make a version using Firebird DB. https://firebirdsql.org  It will be far superior to the sqlite3 version, but the app will support both. 

**Version**: PHP 8.4.1 and Above  
**Author**: [chatgpt]

## Table of Contents

1. [Introduction](#introduction)
2. [System Requirements](#system-requirements)
3. [How the App Works](#how-the-app-works)
4. [Features](#features)
5. [Installation Instructions](#installation-instructions)
6. [Usage Guide](#usage-guide)
7. [Security Measures](#security-measures)
8. [Known Issues & Limitations](#known-issues-limitations)

## Introduction

This application is an imageboard, (a fork of Claire) where users can post images and messages either as original posts or as replies. It is designed for PHP 8.4.1 and above, incorporating modern PHP standards and security best practices to provide a safe, user-friendly platform for public or private boards.

## System Requirements

- **PHP**: Version 8.4.1 or higher is required. The application uses modern PHP syntax and functions that are compatible only with the latest versions.
- **Web Server**: Apache or Nginx recommended.
- **SQLite**: For database storage.
- **FFmpeg and ImageMagick**: Required for handling video thumbnails and image manipulation.
- **File Permissions**: The application requires appropriate permissions for the database (`/db` folder) to be writable.

## How the App Works

This imageboard application is built to allow users to create threads, post messages, and optionally upload media files like images and videos. Here is a detailed explanation of how each part of the application works:

### 1. **Posting a New Thread or Reply**
   - Users can initiate new threads or reply to existing threads by filling out a form that accepts text messages and optional media uploads.
   - Media uploads can be images (`jpg`, `jpeg`, `png`, `gif`) or videos (`mp4`, `webm`), with a maximum size limit of 20 MB.
   - Threads and replies are saved in an SQLite database (`database.db`), and each post is timestamped.

### 2. **CSRF Protection**
   - CSRF (Cross-Site Request Forgery) tokens are utilized to ensure that all form submissions originate from trusted sources, thereby mitigating CSRF attacks.

### 3. **Media Handling**
   - Uploaded images and videos are handled based on their MIME type.
   - Images have thumbnails generated using **ImageMagick**, while **FFmpeg** is used for extracting video thumbnails.
   - Videos are embedded inline using HTML5 video tags, allowing direct playback within the application.

### 4. **Pagination and Thread Management**
   - Threads are paginated to prevent overwhelming the UI, with the default setting of displaying 10 threads per page.
   - Replies are loaded in a threaded format to ensure clarity and context.

### 5. **Form Submission and Feedback**
   - After posting, users are redirected either back to the main board or the respective thread.
   - A detailed form is used for posting, with a "Post" button positioned at the bottom right for better usability.

## Features

- **Threaded Discussions**: Users can start threads and reply to existing threads.
- **Image and Video Uploads**: Users can upload images or videos up to 20 MB.
- **Thumbnail Generation**: Images and videos have thumbnails generated for easy browsing.
- **Pagination**: Threads are paginated to maintain a clean and user-friendly interface.
- **CSRF Protection**: Prevent unauthorized requests from compromising data.
- **Input Sanitization**: All user inputs are sanitized to prevent XSS (Cross-Site Scripting).
- **Session Security**: Secure session management, including prevention against session fixation.

## Installation Instructions

Follow these steps to set up the imageboard application:

1. **Clone the Repository**: Clone the repository to your web server's root directory.
   ```bash
   git clone [repository URL] /path/to/webserver
   ```

2. **Set Permissions**: Ensure the `/db` directory has the correct permissions.
   ```bash
   mkdir db
   chmod 0777 db
   ```

3. **Install Dependencies**:
   - **FFmpeg** and **ImageMagick** must be installed for handling videos and images respectively.
   ```bash
   sudo apt-get install ffmpeg imagemagick
   ```

4. **Configure Your Web Server**: Ensure that your server is configured to support PHP 8.4.1 or above.
   - For **Apache**, you may need to enable the PHP module:
   ```bash
   sudo a2enmod php8.4
   ```

5. **Start the Server**: Start your Apache or Nginx server and navigate to the root URL of your installation.

## Usage Guide

- **Creating a Thread**: On the main page, fill in the "Message" field to start a new thread. Optionally, attach an image or video.
- **Replying to a Thread**: Navigate to an existing thread and fill in the "Reply" field to add your response.
- **Pagination**: Use the pagination links at the bottom of the page to navigate through threads.

### Forms and Fields
- **Message/Reply**: Text area for users to post their content. This field is required.
- **File Upload**: Optional file input for uploading images or videos. Supported formats include `jpg`, `jpeg`, `png`, `gif`, `mp4`, and `webm`.

## Security Measures

This application incorporates several modern security measures to ensure data integrity and protect user information:

### 1. **CSRF Protection**
   - All forms include a hidden input containing a CSRF token, which is validated upon submission to prevent CSRF attacks. The CSRF token is stored in the user's session and must match the token submitted with the form.

### 2. **Session Security**
   - **HttpOnly** and **Secure** flags are set on session cookies to prevent JavaScript access and ensure secure transmission over HTTPS.
   - **SameSite** attribute is set to `Strict` to mitigate CSRF attacks by restricting how cookies are sent along with cross-site requests.
   - **Session Regeneration**: Sessions are regenerated upon important events to prevent session fixation attacks.

### 3. **Input Sanitization**
   - User input is sanitized using `htmlspecialchars()` to prevent XSS attacks. This function escapes special characters like `<` and `>`, rendering any potential script harmless.

### 4. **File Upload Security**
   - Only specific file types are allowed (`jpg`, `jpeg`, `png`, `gif`, `webm`, `mp4`).
   - MIME type validation is used to ensure files match expected formats.
   - Filenames are randomized using `random_bytes()` to avoid directory traversal attacks or overwriting existing files.
   - The **ImageMagick** and **FFmpeg** tools are used securely, with commands executed using `escapeshellcmd()` to prevent command injection.
   - File permissions are restricted to `0644` after upload to prevent unauthorized modification.

### 5. **Database Security**
   - SQLite is used to store posts. Prepared statements are used throughout to prevent SQL injection.

### 6. **Clickjacking Protection**
   - The `X-Frame-Options: DENY` header is set to prevent the site from being embedded in iframes, mitigating clickjacking attacks.

### 7. **Error Handling**
   - Errors are logged to a file (`error.log`) instead of being displayed to users. This prevents potential information leakage about the server's structure.

## Known Issues & Limitations

- **Single User Authentication**: This application currently lacks user authentication. Adding user accounts and permissions would improve its utility for private boards.
- **Limited Scalability**: Since SQLite is used, this application is ideal for small to medium communities. Scaling to thousands of users may require transitioning to a more robust DBMS like MySQL or PostgreSQL.
- **No Rate Limiting**: Currently, there is no mechanism to prevent spam. Adding rate limiting or CAPTCHA is recommended for production use.

## Conclusion

This imageboard application is designed to provide a straightforward, easy-to-use platform for creating and responding to threads. It leverages modern PHP (version 8.4.1 and above) and includes several essential security features to ensure a safe user experience.

Should you have any questions or need further customization, feel free to reach out or contribute to the repository!

