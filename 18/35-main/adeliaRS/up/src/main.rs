// src/main.rs

mod board; // Import the board module

use board::get_board_name; // Use only the get_board_name function
use actix_files as fs;
use actix_multipart::Multipart;
use actix_web::{
    web, App, HttpResponse, HttpServer, middleware, Error, http::header::LOCATION,
};
use chrono::Utc;
use serde::{Deserialize, Serialize};
use futures_util::stream::StreamExt;
use std::io::Write;
use uuid::Uuid;
use html_escape::encode_safe;
use mime_guess::mime;
use dotenv::dotenv;
use sqlx::{Pool, Postgres, Row, Transaction}; // Ensure Executor is imported

const IMAGE_UPLOAD_DIR: &str = "./uploads/images/";
const VIDEO_UPLOAD_DIR: &str = "./uploads/videos/";
const IMAGE_THUMB_DIR: &str = "./thumbs/images/";
const ADMIN_PASSWORD: &str = "af3"; // Change this to your desired admin password

#[derive(Serialize, Deserialize, Clone)]
enum MediaType {
    Image,
    Video,
}

#[derive(Serialize, Deserialize, Clone, sqlx::FromRow)]
struct Thread {
    id: i32,
    board_id: i32,
    title: String,
    message: String,
    last_updated: i64,
    media_url: Option<String>,
    media_type: Option<String>,
}

#[derive(Serialize, Deserialize, sqlx::FromRow)]
struct Reply {
    id: i32,
    thread_id: i32,
    message: String,
}

#[derive(Serialize, Deserialize, sqlx::FromRow)]
struct Board {
    id: i32,
    name: String,
    deleted: bool,
}

#[derive(Deserialize)]
struct PaginationParams {
    page: Option<i32>,
}

#[derive(Deserialize)]
struct ReplyForm {
    thread_id: i32,
    message: String,
}

// Removed the original get_board_name function from here

fn escape_html(input: &str) -> String {
    encode_safe(input).to_string()
}

fn render_error_page(title: &str, message: &str) -> String {
    format!(
        r#"<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Error</title>
    <link rel="stylesheet" href="/static/style.css">
</head>
<body>
    <h1>{}</h1>
    <p>{}</p>
    <a href="/">[Home]</a>
</body>
</html>"#,
        escape_html(title),
        escape_html(message)
    )
}

fn render_reply(reply: &Reply) -> String {
    // Add small [x] link for deleting the reply at the bottom left
    let admin_controls = format!(
        r#"<a href="/admin/reply/delete/{}" class="admin-controls">[x]</a>"#,
        reply.id
    );

    format!(
        r#"<div class="post reply-post">
    <div class="post-content">
        <div class="post-header">
            <span class="title">Reply {}</span>
        </div>
        <div class="message">{}</div>
        <div class="post-footer">
            {}
        </div>
    </div>
</div>"#,
        reply.id,
        escape_html(&reply.message),
        admin_controls
    )
}

fn check_password(input: &str) -> bool {
    input == ADMIN_PASSWORD
}

fn render_password_prompt(action_url: &str, title: &str, prompt: &str) -> String {
    format!(
        r#"<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{}</title>
    <link rel="stylesheet" href="/static/style.css">
</head>
<body>
    <h1>{}</h1>
    <form action="{}" method="post">
        <p>{}</p>
        <input type="password" name="password" placeholder="Admin Password" required>
        <input type="submit" value="Submit">
    </form>
    <p><a href="/">[Home]</a></p>
</body>
</html>"#,
        escape_html(title),
        escape_html(title),
        escape_html(action_url),
        escape_html(prompt)
    )
}

#[derive(Deserialize)]
struct AdminActionForm {
    password: String,
}

// Homepage
async fn homepage(pool: web::Data<Pool<Postgres>>) -> Result<HttpResponse, Error> {
    // Fetch all boards from the database that are not deleted
    let boards_db = sqlx::query_as::<_, Board>(
        "SELECT id, name, deleted FROM boards WHERE deleted = false ORDER BY id ASC",
    )
    .fetch_all(pool.get_ref())
    .await
    .map_err(actix_web::error::ErrorInternalServerError)?;

    // Filter boards that have a defined name in board.rs
    let boards_defined: Vec<BoardInfoDefined> = boards_db
        .iter()
        .filter_map(|board| {
            // Use get_board_name to check if the board is defined
            get_board_name(board.id).map(|name| BoardInfoDefined {
                id: board.id,
                name: name.to_string(),
            })
        })
        .collect();

    let board_list_html = if boards_defined.is_empty() {
        "<p>No boards found.</p>".to_string()
    } else {
        boards_defined
            .into_iter()
            .map(|board| {
                format!(
                    r#"<p><a href="/board/{}">[{}]</a></p>"#,
                    board.id,
                    escape_html(&board.name)
                )
            })
            .collect::<Vec<String>>()
            .join("")
    };

    let html = format!(
        r#"<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>4Chess Boards</title>
    <link rel="stylesheet" href="/static/style.css">
    <script defer src="/static/script.js"></script>
</head>
<body>
    <div class="logo">4Chess Boards</div>
    <hr>
    <h2>Available Boards</h2>
    {}
</body>
</html>"#,
        board_list_html,
    );

    Ok(HttpResponse::Ok().content_type("text/html").body(html))
}

struct BoardInfoDefined {
    id: i32,
    name: String,
}

// Board page
async fn board_page(
    pool: web::Data<Pool<Postgres>>,
    path: web::Path<(i32,)>,
    query: web::Query<PaginationParams>,
) -> Result<HttpResponse, Error> {
    let board_id = path.into_inner().0;

    // Verify if the board is defined using get_board_name
    let board_name = match get_board_name(board_id) {
        Some(name) => name,
        None => {
            return Ok(HttpResponse::NotFound().body(
                render_error_page("Not Found", "Board does not exist or has been deleted."),
            ));
        }
    };

    let page_size = 10;
    let page_number = query.page.unwrap_or(1).max(1);

    let total_threads: i64 = sqlx::query_scalar("SELECT COUNT(*) FROM threads WHERE board_id = $1")
        .bind(board_id)
        .fetch_one(pool.get_ref())
        .await
        .map_err(actix_web::error::ErrorInternalServerError)?;

    let total_pages = ((total_threads as f64) / (page_size as f64)).ceil() as i32;
    let page_number = if total_pages > 0 && page_number > total_pages {
        total_pages
    } else {
        page_number
    };
    let offset = (page_number - 1) * page_size;

    let threads = sqlx::query_as::<_, Thread>(
        r#"
        SELECT id, board_id, title, message, last_updated, media_url, media_type
        FROM threads
        WHERE board_id = $1
        ORDER BY last_updated DESC
        LIMIT $2 OFFSET $3
        "#,
    )
    .bind(board_id)
    .bind(page_size)
    .bind(offset)
    .fetch_all(pool.get_ref())
    .await
    .map_err(actix_web::error::ErrorInternalServerError)?;

    let thread_list_html = if threads.is_empty() {
        "<p>No threads found. Create one!</p>".to_string()
    } else {
        threads
            .into_iter()
            .map(|t| render_thread(&t))
            .collect::<Vec<String>>()
            .join("<hr>")
    };

    let mut pagination_html = String::new();
    pagination_html.push_str(r#"<div class="pagination">"#);
    if page_number > 1 {
        pagination_html.push_str(&format!(
            "<a href=\"/board/{}?page={}\">Previous</a>",
            board_id,
            page_number - 1
        ));
    }
    for p in 1..=total_pages {
        if p == page_number {
            pagination_html.push_str(&format!("<span class=\"current\">{}</span>", p));
        } else {
            pagination_html.push_str(&format!(
                "<a href=\"/board/{}?page={}\">{}</a>",
                board_id, p, p
            ));
        }
    }
    if page_number < total_pages {
        pagination_html.push_str(&format!(
            "<a href=\"/board/{}?page={}\">Next</a>",
            board_id,
            page_number + 1
        ));
    }
    pagination_html.push_str("</div>");

    let html = format!(
        r#"<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{}</title>
    <link rel="stylesheet" href="/static/style.css">
    <script defer src="/static/script.js"></script>
</head>
<body>
    <div class="navigation-board">
        <hr class="hr-green">
        <a href="/">[Home]</a>
    </div>
    <h2>{}</h2>
    <form class="postform" action="/board/{}/thread" method="post" enctype="multipart/form-data">
        <input type="text" id="title" name="title" maxlength="75" placeholder="Title" required>
        <textarea id="message" name="message" rows="4" maxlength="8000" placeholder="Message" required></textarea>
        <label>Upload Media (JPEG, PNG, GIF, WEBP, MP4):</label>
        <input type="file" name="media" accept=".jpg,.jpeg,.png,.gif,.webp,.mp4">
        <input type="submit" value="Create Thread">
    </form>
    <hr>
    <div class="postlists">{}</div>
    {}
</body>
</html>"#,
        escape_html(board_name),
        escape_html(board_name),
        board_id,
        thread_list_html,
        pagination_html
    );

    Ok(HttpResponse::Ok().content_type("text/html").body(html))
}

fn render_thread(thread: &Thread) -> String {
    let media_html = if let Some(url) = &thread.media_url {
        if let Some(mt) = &thread.media_type {
            if mt == "image" {
                format!(
                    r#"<div class="post-media">
<img src="{}" alt="Thread Image" class="toggle-image">
</div>"#,
                    escape_html(url)
                )
            } else {
                format!(
                    r#"<div class="post-media">
<video controls class="video-player">
    <source src="{}" type="video/mp4">
    Your browser does not support the video tag.
</video>
</div>"#,
                    escape_html(url)
                )
            }
        } else {
            "".to_string()
        }
    } else {
        "".to_string()
    };

    // Add [x] for deletion at the bottom left
    let admin_controls = format!(
        r#"<a href="/admin/thread/delete/{}" class="admin-controls">[x]</a>"#,
        thread.id
    );

    format!(
        r#"<div class="post thread-post">
{}
<div class="post-content">
    <div class="post-header">
        <span class="title">{}</span> <a class="reply-link" href="/thread/{}">Reply</a>
    </div>
    <div class="message">{}</div>
    <div class="post-footer">
        {}
    </div>
</div>
</div>"#,
        media_html,
        escape_html(&thread.title),
        thread.id,
        escape_html(&thread.message),
        admin_controls
    )
}

// View a single thread
async fn view_thread(
    pool: web::Data<Pool<Postgres>>,
    path: web::Path<(i32,)>,
) -> Result<HttpResponse, Error> {
    let thread_id = path.into_inner().0;
    let thread: Option<Thread> = sqlx::query_as(
        r#"SELECT id, board_id, title, message, last_updated, media_url, media_type
        FROM threads WHERE id = $1"#,
    )
    .bind(thread_id)
    .fetch_optional(pool.get_ref())
    .await
    .map_err(actix_web::error::ErrorInternalServerError)?;

    if thread.is_none() {
        return Ok(HttpResponse::NotFound().body(
            render_error_page("Not Found", "Thread not found."),
        ));
    }

    let thread = thread.unwrap();

    let replies = sqlx::query_as::<_, Reply>(
        "SELECT id, thread_id, message FROM replies WHERE thread_id = $1 ORDER BY id ASC",
    )
    .bind(thread_id)
    .fetch_all(pool.get_ref())
    .await
    .map_err(actix_web::error::ErrorInternalServerError)?;

    let replies_html = if replies.is_empty() {
        "<p>No replies yet.</p>".to_string()
    } else {
        replies
            .into_iter()
            .map(|r| render_reply(&r))
            .collect::<Vec<String>>()
            .join("<hr>")
    };

    let media_html = if let Some(url) = &thread.media_url {
        if let Some(mt) = &thread.media_type {
            if mt == "image" {
                format!(
                    r#"<div class="post-media">
<img src="{}" alt="Thread Image" class="toggle-image">
</div>"#,
                    escape_html(url)
                )
            } else {
                format!(
                    r#"<div class="post-media">
<video controls class="video-player">
    <source src="{}" type="video/mp4">
    Your browser does not support the video tag.
</video>
</div>"#,
                    escape_html(url)
                )
            }
        } else {
            "".to_string()
        }
    } else {
        "".to_string()
    };

    let reply_form = format!(
        r#"<form class="postform" action="/reply" method="post">
<input type="hidden" name="thread_id" value="{}">
<textarea name="message" rows="4" maxlength="8000" placeholder="Message" required></textarea>
<input type="submit" value="Reply">
</form>"#,
        thread_id
    );

    let admin_controls = format!(
        r#" <a href="/admin/thread/delete/{}" class="admin-controls">[x]</a>"#,
        thread_id
    );

    // Fetch the board ID to create the back to board link
    let board_id = thread.board_id;
    let board_name = match get_board_name(board_id) {
        Some(name) => name,
        None => "Unknown Board",
    };

    let html = format!(
        r#"<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{} - {}</title>
    <link rel="stylesheet" href="/static/style.css">
    <script defer src="/static/script.js"></script>
</head>
<body>
    <div class="navigation-reply">
        <hr>
        <a href="/board/{}">Back to Board</a> | <a href="/">[Home]</a>
    </div>
    <h2>{}</h2>
    {}
    <div class="post thread-post">
        {}
        <div class="post-content">
            <div class="post-header">
                <span class="title">{}</span> <a class="reply-link" href="/thread/{}">Reply</a>
            </div>
            <div class="message">{}</div>
            <div class="post-footer">
                {}
            </div>
        </div>
    </div>
    <hr>
    {}
    <hr>
    <div class="postlists">
        {}
    </div>
</body>
</html>"#,
        escape_html(board_name),            // Using board_name in the title
        escape_html(&thread.title),
        board_id,
        escape_html(&thread.title),
        media_html,
        "",
        escape_html(&thread.title),
        thread.id,
        escape_html(&thread.message),
        reply_form,
        admin_controls,
        replies_html
    );

    Ok(HttpResponse::Ok().content_type("text/html").body(html))
}

async fn create_thread(
    pool: web::Data<Pool<Postgres>>,
    board_id: web::Path<(i32,)>,
    mut payload: Multipart,
) -> Result<HttpResponse, Error> {
    let board_id = board_id.into_inner().0;

    // Verify if the board is defined
    if get_board_name(board_id).is_none() {
        return Ok(HttpResponse::NotFound().body(
            render_error_page("Not Found", "Board does not exist or has been deleted."),
        ));
    }

    let mut title = String::new();
    let mut message = String::new();
    let mut media_url: Option<String> = None;
    let mut media_type: Option<String> = None;

    // Handling multipart form data
    while let Some(item) = payload.next().await {
        let mut field = item.map_err(actix_web::error::ErrorInternalServerError)?;
        let cd = field.content_disposition();

        let name = if let Some(name) = cd.get_name() {
            name
        } else {
            continue;
        };

        match name {
            "title" => {
                while let Some(chunk) = field.next().await {
                    let data = chunk.map_err(actix_web::error::ErrorInternalServerError)?;
                    title.push_str(&String::from_utf8_lossy(&data));
                }
            }
            "message" => {
                while let Some(chunk) = field.next().await {
                    let data = chunk.map_err(actix_web::error::ErrorInternalServerError)?;
                    message.push_str(&String::from_utf8_lossy(&data));
                }
            }
            "media" => {
                if let Some(filename) = cd.get_filename() {
                    if !filename.trim().is_empty() {
                        let mime_type = mime_guess::from_path(&filename).first_or_octet_stream();
                        if mime_type.type_() == mime::IMAGE {
                            let extension = mime_type.subtype().as_str();
                            if !matches!(extension, "jpeg" | "png" | "gif" | "webp") {
                                return Ok(HttpResponse::BadRequest()
                                    .body("Unsupported image format"));
                            }

                            let unique_id = Uuid::new_v4().to_string();
                            let sanitized_filename = format!("{}.{}", unique_id, extension);
                            let filepath = format!("{}{}", IMAGE_UPLOAD_DIR, sanitized_filename);
                            let mut f = std::fs::File::create(&filepath)
                                .map_err(actix_web::error::ErrorInternalServerError)?;
                            while let Some(chunk) = field.next().await {
                                let data =
                                    chunk.map_err(actix_web::error::ErrorInternalServerError)?;
                                f.write_all(&data)
                                    .map_err(actix_web::error::ErrorInternalServerError)?;
                            }

                            if image::open(&filepath).is_err() {
                                std::fs::remove_file(&filepath).ok();
                                return Ok(HttpResponse::BadRequest()
                                    .body("Invalid image file"));
                            }

                            media_url = Some(format!("/uploads/images/{}", sanitized_filename));
                            media_type = Some("image".to_string());
                        } else if mime_type.type_() == mime::VIDEO {
                            let extension = mime_type.subtype().as_str();
                            if extension != "mp4" {
                                return Ok(HttpResponse::BadRequest()
                                    .body("Unsupported video format"));
                            }
                            let unique_id = Uuid::new_v4().to_string();
                            let sanitized_filename = format!("{}.mp4", unique_id);
                            let filepath = format!("{}{}", VIDEO_UPLOAD_DIR, sanitized_filename);
                            let mut f = std::fs::File::create(&filepath)
                                .map_err(actix_web::error::ErrorInternalServerError)?;
                            while let Some(chunk) = field.next().await {
                                let data =
                                    chunk.map_err(actix_web::error::ErrorInternalServerError)?;
                                f.write_all(&data)
                                    .map_err(actix_web::error::ErrorInternalServerError)?;
                            }
                            media_url = Some(format!("/uploads/videos/{}", sanitized_filename));
                            media_type = Some("video".to_string());
                        }
                    }
                }
            }
            _ => {}
        }
    }

    if title.trim().is_empty() || message.trim().is_empty() {
        return Ok(HttpResponse::BadRequest()
            .content_type("text/html")
            .body(render_error_page(
                "Bad Request",
                "Title and Message cannot be empty",
            )));
    }

    let now = Utc::now().timestamp();

    let record = sqlx::query(
        "INSERT INTO threads (board_id, title, message, last_updated, media_url, media_type) VALUES ($1, $2, $3, $4, $5, $6) RETURNING id",
    )
    .bind(board_id)
    .bind(title.trim())
    .bind(message.trim())
    .bind(now)
    .bind(media_url)
    .bind(media_type)
    .fetch_one(pool.get_ref())
    .await
    .map_err(actix_web::error::ErrorInternalServerError)?;

    let id: i32 = record
        .try_get("id")
        .map_err(actix_web::error::ErrorInternalServerError)?;

    Ok(HttpResponse::SeeOther()
        .insert_header((LOCATION, format!("/thread/{}", id)))
        .finish())
}

// Create a reply
async fn create_reply(
    pool: web::Data<Pool<Postgres>>,
    form: web::Form<ReplyForm>,
) -> Result<HttpResponse, Error> {
    use sqlx::Executor; // Re-import Executor inside the function scope

    let message = form.message.trim();
    if message.is_empty() {
        return Ok(HttpResponse::BadRequest().body("Message cannot be empty"));
    }

    let thread_id = form.thread_id;
    let now = Utc::now().timestamp();

    // Begin a transaction
    let mut tx: Transaction<'_, Postgres> = pool
        .begin()
        .await
        .map_err(actix_web::error::ErrorInternalServerError)?;

    // Insert the reply
    tx.execute(
        sqlx::query("INSERT INTO replies (thread_id, message) VALUES ($1, $2)")
            .bind(thread_id)
            .bind(message),
    )
    .await
    .map_err(actix_web::error::ErrorInternalServerError)?;

    // Update the thread's last_updated timestamp
    tx.execute(
        sqlx::query("UPDATE threads SET last_updated = $1 WHERE id = $2")
            .bind(now)
            .bind(thread_id),
    )
    .await
    .map_err(actix_web::error::ErrorInternalServerError)?;

    // Commit the transaction
    tx.commit()
        .await
        .map_err(actix_web::error::ErrorInternalServerError)?;

    Ok(HttpResponse::SeeOther()
        .insert_header((LOCATION, format!("/thread/{}", thread_id)))
        .finish())
}

// ADMIN: Delete Thread
async fn admin_delete_thread_form(path: web::Path<(i32,)>) -> HttpResponse {
    let thread_id = path.into_inner().0;
    let action_url = format!("/admin/thread/delete/{}", thread_id);
    let html = render_password_prompt(
        &action_url,
        "Delete Thread",
        "Enter admin password to delete this thread:",
    );
    HttpResponse::Ok().content_type("text/html").body(html)
}

async fn admin_delete_thread_action(
    pool: web::Data<Pool<Postgres>>,
    path: web::Path<(i32,)>,
    form: web::Form<AdminActionForm>,
) -> Result<HttpResponse, Error> {
    if !check_password(&form.password) {
        return Ok(HttpResponse::Forbidden().body("Invalid password"));
    }

    let thread_id = path.into_inner().0;
    sqlx::query("DELETE FROM threads WHERE id = $1")
        .bind(thread_id)
        .execute(pool.get_ref())
        .await
        .map_err(actix_web::error::ErrorInternalServerError)?;

    // Optionally, delete associated replies if not using ON DELETE CASCADE
    // sqlx::query("DELETE FROM replies WHERE thread_id = $1")
    //     .bind(thread_id)
    //     .execute(pool.get_ref())
    //     .await
    //     .map_err(actix_web::error::ErrorInternalServerError)?;

    Ok(HttpResponse::SeeOther()
        .insert_header((LOCATION, "/"))
        .finish())
}

// ADMIN: Delete Reply
async fn admin_delete_reply_form(path: web::Path<(i32,)>) -> HttpResponse {
    let reply_id = path.into_inner().0;
    let action_url = format!("/admin/reply/delete/{}", reply_id);
    let html = render_password_prompt(
        &action_url,
        "Delete Reply",
        "Enter admin password to delete this reply:",
    );
    HttpResponse::Ok().content_type("text/html").body(html)
}

async fn admin_delete_reply_action(
    pool: web::Data<Pool<Postgres>>,
    path: web::Path<(i32,)>,
    form: web::Form<AdminActionForm>,
) -> Result<HttpResponse, Error> {
    if !check_password(&form.password) {
        return Ok(HttpResponse::Forbidden().body("Invalid password"));
    }

    let reply_id = path.into_inner().0;

    // Need thread_id to redirect back to thread after deletion
    let thread_id: Option<i32> = sqlx::query_scalar("SELECT thread_id FROM replies WHERE id = $1")
        .bind(reply_id)
        .fetch_optional(pool.get_ref())
        .await
        .map_err(actix_web::error::ErrorInternalServerError)?;

    sqlx::query("DELETE FROM replies WHERE id = $1")
        .bind(reply_id)
        .execute(pool.get_ref())
        .await
        .map_err(actix_web::error::ErrorInternalServerError)?;

    let redirect_url = if let Some(tid) = thread_id {
        format!("/thread/{}", tid)
    } else {
        "/".to_string()
    };

    Ok(HttpResponse::SeeOther()
        .insert_header((LOCATION, redirect_url))
        .finish())
}

// ADMIN: Delete Board
async fn admin_delete_board_form(path: web::Path<(i32,)>) -> HttpResponse {
    let board_id = path.into_inner().0;
    let action_url = format!("/admin/boards/delete/{}", board_id);
    let html = render_password_prompt(
        &action_url,
        "Delete Board",
        "Enter admin password to delete this board:",
    );
    HttpResponse::Ok().content_type("text/html").body(html)
}

async fn admin_delete_board_action(
    pool: web::Data<Pool<Postgres>>,
    path: web::Path<(i32,)>,
    form: web::Form<AdminActionForm>,
) -> Result<HttpResponse, Error> {
    if !check_password(&form.password) {
        return Ok(HttpResponse::Forbidden().body("Invalid password"));
    }

    let board_id = path.into_inner().0;
    sqlx::query("UPDATE boards SET deleted = TRUE WHERE id = $1")
        .bind(board_id)
        .execute(pool.get_ref())
        .await
        .map_err(actix_web::error::ErrorInternalServerError)?;

    Ok(HttpResponse::SeeOther()
        .insert_header((LOCATION, "/"))
        .finish())
}

// ADMIN: Edit Board
#[derive(Deserialize)]
struct EditBoardData {
    password: String,
    name: String,
}

async fn admin_edit_board_form(path: web::Path<(i32,)>) -> HttpResponse {
    let board_id = path.into_inner().0;
    let action_url = format!("/admin/boards/edit/{}", board_id);
    let html = format!(
        r#"<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Edit Board</title>
    <link rel="stylesheet" href="/static/style.css">
</head>
<body>
    <h1>Edit Board {}</h1>
    <form action="{}" method="post">
        <p>Enter admin password and new board name:</p>
        <input type="password" name="password" placeholder="Admin Password" required><br>
        <input type="text" name="name" placeholder="New Board Name" required>
        <input type="submit" value="Update">
    </form>
    <p><a href="/">[Home]</a></p>
</body>
</html>"#,
        board_id, action_url
    );
    HttpResponse::Ok().content_type("text/html").body(html)
}

async fn admin_edit_board_action(
    pool: web::Data<Pool<Postgres>>,
    path: web::Path<(i32,)>,
    form: web::Form<EditBoardData>,
) -> Result<HttpResponse, Error> {
    if !check_password(&form.password) {
        return Ok(HttpResponse::Forbidden().body("Invalid password"));
    }
    let board_id = path.into_inner().0;
    sqlx::query("UPDATE boards SET name = $1 WHERE id = $2")
        .bind(&form.name)
        .bind(board_id)
        .execute(pool.get_ref())
        .await
        .map_err(actix_web::error::ErrorInternalServerError)?;
    Ok(HttpResponse::SeeOther()
        .insert_header((LOCATION, "/"))
        .finish())
}

#[actix_web::main]
async fn main() -> std::io::Result<()> {
    dotenv().ok();
    env_logger::init();

    for dir in &[IMAGE_UPLOAD_DIR, VIDEO_UPLOAD_DIR, IMAGE_THUMB_DIR] {
        if !std::path::Path::new(dir).exists() {
            std::fs::create_dir_all(dir).ok();
        }
    }

    let database_url = std::env::var("DATABASE_URL").expect("DATABASE_URL must be set");
    let pool = Pool::<Postgres>::connect(&database_url)
        .await
        .expect("Failed to connect to DB");

    HttpServer::new(move || {
        App::new()
            .app_data(web::Data::new(pool.clone()))
            .wrap(middleware::Logger::default())
            .service(fs::Files::new("/static", "./static"))
            .service(fs::Files::new("/uploads/images", IMAGE_UPLOAD_DIR))
            .service(fs::Files::new("/uploads/videos", VIDEO_UPLOAD_DIR))
            .service(fs::Files::new("/thumbs/images", IMAGE_THUMB_DIR))
            // Public routes
            .route("/", web::get().to(homepage))
            .route("/board/{id}", web::get().to(board_page))
            .route("/board/{id}/thread", web::post().to(create_thread))
            .route("/thread/{id}", web::get().to(view_thread))
            .route("/reply", web::post().to(create_reply))
            // Admin routes for deletion and edit
            .route("/admin/thread/delete/{id}", web::get().to(admin_delete_thread_form))
            .route("/admin/thread/delete/{id}", web::post().to(admin_delete_thread_action))
            .route("/admin/reply/delete/{id}", web::get().to(admin_delete_reply_form))
            .route("/admin/reply/delete/{id}", web::post().to(admin_delete_reply_action))
            .route("/admin/boards/delete/{id}", web::get().to(admin_delete_board_form))
            .route("/admin/boards/delete/{id}", web::post().to(admin_delete_board_action))
            .route("/admin/boards/edit/{id}", web::get().to(admin_edit_board_form))
            .route("/admin/boards/edit/{id}", web::post().to(admin_edit_board_action))
    })
    .bind(("0.0.0.0", 8080))?
    .run()
    .await
}
