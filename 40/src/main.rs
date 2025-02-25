use actix_files as fs;
use actix_multipart::Multipart;
use actix_web::{web, App, HttpResponse, HttpServer, Responder};
use futures_util::stream::StreamExt as _;
use sanitize_filename::sanitize;
use sled::Db;
use std::io::Write;
use std::path::Path;

/// Simple HTML escape function for &, <, and >
fn html_escape(input: &str) -> String {
    input
        .replace('&', "&amp;")
        .replace('<', "&lt;")
        .replace('>', "&gt;")
}

#[derive(Clone, serde::Serialize, serde::Deserialize)]
struct Post {
    subject: String,
    message: String,
    file_path: Option<String>,
}

/// GET "/" - Render the main page with the post form and list of posts.
/// Posts are loaded from the sled database, sorted with the newest first,
/// and each post is rendered in its own styled box.
async fn index(db: web::Data<Db>) -> impl Responder {
    let mut posts_vec: Vec<(u64, Post)> = Vec::new();

    // Load posts from the sled database.
    for item in db.iter() {
        if let Ok((key_bytes, value)) = item {
            if key_bytes.len() == 8 {
                let id = u64::from_be_bytes(key_bytes.as_ref().try_into().unwrap());
                if let Ok(post) = serde_json::from_slice::<Post>(&value) {
                    posts_vec.push((id, post));
                }
            }
        }
    }
    // Sort descending by id (newest posts first).
    posts_vec.sort_by(|a, b| b.0.cmp(&a.0));

    // Build a full HTML document with CSS.
    let mut html = String::new();
    html.push_str("<!DOCTYPE html><html><head><meta charset='utf-8'><title>Simple Imageboard</title>");
    html.push_str("<style>");
    html.push_str("body { font-family: Arial, sans-serif; background-color: #f8f8f8; margin: 20px; }");
    html.push_str(".post { background-color: #fff; border: 1px solid #ccc; border-radius: 4px; padding: 10px; margin-bottom: 20px; max-width: 600px; word-wrap: break-word; }");
    html.push_str(".post h3 { margin: 0 0 10px 0; }");
    html.push_str(".post img { max-width: 100%; height: auto; display: block; margin-bottom: 10px; }");
    html.push_str(".post p { white-space: pre-wrap; margin: 0; }");
    html.push_str("</style></head><body>");
    html.push_str(r#"
        <form action="/" method="post" enctype="multipart/form-data">
            <input type="text" name="subject" placeholder="Subject (max 30 chars)" required maxlength="30"><br>
            <textarea name="message" placeholder="Message (up to 20,000 characters)" rows="4" cols="50" maxlength="20000"></textarea><br>
            <input type="file" name="file" accept=".jpg,.jpeg,.gif,.png,.webp"><br>
            <input type="submit" value="Post">
        </form>
        <hr>
    "#);
    // Render each post.
    for (_, post) in posts_vec {
        html.push_str("<div class='post'>");
        html.push_str(&format!("<h3>{}</h3>", html_escape(&post.subject)));
        if let Some(ref file_path) = post.file_path {
            let file_name = Path::new(file_path)
                .file_name()
                .and_then(|s| s.to_str())
                .unwrap_or("");
            html.push_str(&format!("<img src='/uploads/{}' />", file_name));
        }
        html.push_str(&format!("<p>{}</p>", html_escape(&post.message)));
        html.push_str("</div>");
    }
    html.push_str("</body></html>");
    HttpResponse::Ok().content_type("text/html").body(html)
}

/// POST "/" - Process the multipart form submission.
/// Validates the subject, message, and file type.
/// Saves the file to "./uploads" (if provided) and stores the post in the sled database.
async fn post_handler(mut payload: Multipart, db: web::Data<Db>) -> actix_web::Result<HttpResponse> {
    let mut subject: Option<String> = None;
    let mut message: Option<String> = None;
    let mut file_path: Option<String> = None;

    while let Some(item) = payload.next().await {
        let mut field = item?;
        let disposition = field.content_disposition();
        let name = disposition.get_name().unwrap_or("");
        if name == "subject" {
            let mut value = Vec::new();
            while let Some(chunk) = field.next().await {
                let data = chunk?;
                value.extend_from_slice(&data);
            }
            subject = Some(String::from_utf8_lossy(&value).to_string().trim().to_string());
        } else if name == "message" {
            let mut value = Vec::new();
            while let Some(chunk) = field.next().await {
                let data = chunk?;
                value.extend_from_slice(&data);
            }
            message = Some(String::from_utf8_lossy(&value).to_string());
        } else if name == "file" {
            if let Some(filename) = disposition.get_filename() {
                let filename = sanitize(filename);
                // Validate file extension.
                let allowed_extensions = ["jpg", "jpeg", "gif", "png", "webp"];
                if let Some(ext) = filename.split('.').last() {
                    if !allowed_extensions.contains(&ext.to_lowercase().as_str()) {
                        return Ok(HttpResponse::BadRequest()
                            .body("Invalid file type. Allowed: jpg, jpeg, gif, png, webp"));
                    }
                }
                let filepath = format!("./uploads/{}", filename);
                let filepath_for_closure = filepath.clone();
                let mut f = web::block(move || std::fs::File::create(filepath_for_closure))
                    .await?
                    .map_err(actix_web::error::ErrorInternalServerError)?;
                while let Some(chunk) = field.next().await {
                    let data = chunk?;
                    f = web::block(move || f.write_all(&data).map(|_| f))
                        .await?
                        .map_err(actix_web::error::ErrorInternalServerError)?;
                }
                file_path = Some(filepath);
            }
        }
    }

    // Validate subject.
    let subject = subject.ok_or_else(|| actix_web::error::ErrorBadRequest("Subject is required"))?;
    if subject.len() > 30 {
        return Ok(HttpResponse::BadRequest().body("Subject must be 30 characters or less"));
    }
    // Validate message length.
    let message = message.unwrap_or_default();
    if message.len() > 20000 {
        return Ok(HttpResponse::BadRequest().body("Message exceeds 20,000 characters"));
    }

    let post = Post {
        subject,
        message,
        file_path,
    };

    let id = db.generate_id().unwrap();
    let key = id.to_be_bytes();
    let value = serde_json::to_vec(&post).unwrap();
    db.insert(key, value).unwrap();
    db.flush().unwrap();

    Ok(HttpResponse::Found()
        .append_header(("Location", "/"))
        .finish())
}

/// Ensures that a directory exists; creates it if not.
/// On Unix, it sets permissions to 0o755.
#[cfg(unix)]
fn ensure_dir<P: AsRef<std::path::Path>>(path: P) -> std::io::Result<()> {
    use std::os::unix::fs::PermissionsExt;
    if !path.as_ref().exists() {
        std::fs::create_dir_all(&path)?;
        std::fs::set_permissions(&path, std::fs::Permissions::from_mode(0o755))?;
    }
    Ok(())
}

#[cfg(not(unix))]
fn ensure_dir<P: AsRef<std::path::Path>>(path: P) -> std::io::Result<()> {
    if !path.as_ref().exists() {
        std::fs::create_dir_all(&path)?;
    }
    Ok(())
}

#[actix_web::main]
async fn main() -> std::io::Result<()> {
    // Ensure required directories exist.
    ensure_dir("./uploads")?;
    ensure_dir("./db")?;

    let db = sled::open("./db").expect("Could not open sled database");

    HttpServer::new(move || {
        App::new()
            .app_data(web::Data::new(db.clone()))
            .route("/", web::get().to(index))
            .route("/", web::post().to(post_handler))
            .service(fs::Files::new("/uploads", "./uploads").show_files_listing())
    })
    .bind("127.0.0.1:8080")?
    .run()
    .await
}
