#  Flatfile-PHP-Imageboards 1-5 (made with ChatGPT 03-mini)

Of course, one can only go so far in flatfile imageboards- there are limitations. Nevertheless, this repo will have various flatfile imageboards that should run fine on any host, even shared hosting. 

Each dir is its own app- the higher the number the more features are implemented. To run any of them just run post.php in a browser, it will set up everything needed. Every app makes an index.html to serve posts from the static html file. 

Just feed post.php to ai (chatgpt or similar) to implement security, change the code to your specific php version  or change anything you want. 

Note: SQLite3 is designed to handle concurrency and transactions much more robustly. While proper file locking on a flatfile can work for moderate traffic, it may not scale as reliably or safely as a dedicated database system like SQLite3 when many users are posting concurrently. Flatfiles with manual locking can lead to delays or even race conditions if not managed extremely carefully, whereas SQLite3 has built-in mechanisms for managing concurrent writes and reads. Therefore, starting at 6, i will add replies to posts but use sqlite3.

# Sqlite3-PHP-Imageboards (made with ChatGPT 03-mini)

6- implements sqlite3/pdo db, replying to posts, post bumping, and is a very nice app for such a small file size. It is about 1/100 of the size of vichan codebase and is about as functional! 

7- Improved version of 6. Best version so far, the critical functionality and logic flow of a fully working ib is there now. From here, it is very easy to change anything you want. Just feed the code to Ai and have it change one small thing at a time. 
