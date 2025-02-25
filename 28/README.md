![demo](https://github.com/user-attachments/assets/6dc722a0-e608-4974-a8d5-b5c7870b3226)


I fed the entire original claire file to chat-gpt and asked it to modernize the code and remove a bunch of features that claire had. This uses sqlite and is not exactly a high end production imageboard. Feed this code to chat gpt or similar if you want anything changed. 

## notes::

CLAIRE_TEXTMODE

If true, it disallows file uploads. That means no images are required (or even possible), so your board becomes text-only.
If false, users can upload images on new threads (and potentially replies, depending on your script’s logic).
TINYIB_PAGETITLE

Used in the <title> tag in the HTML <head> and possibly in your header/logo area.
Change it to customize the name of your board.
TINYIB_THREADSPERPAGE

How many threads to show on each “index” page (when you do ?do=page&p=0, etc.).
If the number of threads is larger than that, you get multiple pages with “next / prev” links.
TINYIB_REPLIESTOSHOW

How many recent replies (per thread) to show on the index page (so users get a quick glimpse).
If a thread has more replies than this, the extras are omitted (with a note like “X posts omitted”).
TINYIB_MAXTHREADS

The maximum total number of threads to keep.
If set to 0, there’s no limit (so no “pruning” of oldest threads).
If set to some integer (e.g., 100), whenever a new thread is created and the total exceeds that, the script deletes the oldest bumped thread(s) to keep it at or below TINYIB_MAXTHREADS.
TINYIB_MAXPOSTSIZE

The maximum character length of the message body.
If a user’s message exceeds this number, the script shows an error like “Your message is too long.”
TINYIB_RATELIMIT

The delay in seconds between posts from the same IP address to prevent spam/flooding.
E.g., if TINYIB_RATELIMIT = 7, a user must wait 7 seconds after making a post before posting again.
TINYIB_THUMBWIDTH / TINYIB_THUMBHEIGHT

Max dimensions (in pixels) for thumbnails of the OP image.
In some boards, OP images might have slightly different or larger allowed thumbnail sizes.
TINYIB_REPLYWIDTH / TINYIB_REPLYHEIGHT

Max dimensions for thumbnails on reply images.
For some imageboard styles, replies have smaller thumbs vs. the OP’s image.
