DROP TABLE IF EXISTS replies;
DROP TABLE IF EXISTS threads;
DROP TABLE IF EXISTS boards;
DROP TABLE IF EXISTS admins;

CREATE TABLE boards (
    id INT PRIMARY KEY,
    name TEXT NOT NULL,
    deleted BOOLEAN NOT NULL DEFAULT FALSE
);

INSERT INTO boards (id, name)
SELECT i, 'Board ' || i FROM generate_series(1,100) AS i;

CREATE TABLE threads (
    id SERIAL PRIMARY KEY,
    board_id INT NOT NULL REFERENCES boards(id) ON DELETE CASCADE,
    title TEXT NOT NULL,
    message TEXT NOT NULL,
    last_updated BIGINT NOT NULL,
    media_url TEXT,
    media_type TEXT,
    pinned BOOLEAN NOT NULL DEFAULT FALSE,
    locked BOOLEAN NOT NULL DEFAULT FALSE
);

CREATE TABLE replies (
    id SERIAL PRIMARY KEY,
    thread_id INT NOT NULL REFERENCES threads(id) ON DELETE CASCADE,
    message TEXT NOT NULL
);

CREATE TABLE admins (
    username TEXT PRIMARY KEY,
    password_hash TEXT NOT NULL
);

INSERT INTO admins (username, password_hash) VALUES ('admin', 'plaintextpassword');
