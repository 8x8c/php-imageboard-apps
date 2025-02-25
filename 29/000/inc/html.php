<?php
if (!defined('TINYIB_BOARD')) {
	die('');
}

function pageHeader() {
	if (TINYIB_BOARDTITLE != '') {
		$title = TINYIB_BOARDTITLE;
	} else if (TINYIB_BOARDDESC != '') {
		$title = TINYIB_BOARDDESC;
	} else {
		$title = 'TinyIB';
	}

	$stylesheets = pageStylesheets();

	return <<<EOF
<!DOCTYPE html>
<html>
	<head>
		<meta http-equiv="content-type" content="text/html;charset=UTF-8">
		<meta http-equiv="cache-control" content="max-age=0">
		<meta http-equiv="cache-control" content="no-cache">
		<meta http-equiv="expires" content="0">
		<meta http-equiv="expires" content="Tue, 01 Jan 1980 1:00:00 GMT">
		<meta http-equiv="pragma" content="no-cache">
		<meta name="viewport" content="width=device-width,initial-scale=1">
		<title>$title</title>
		<link rel="shortcut icon" href="favicon.ico">
		$stylesheets
		<script src="js/jquery.js"></script>
		<script src="js/tinyib.js"></script>
		
	</head>
EOF;
}

function pageStylesheets() {
	global $tinyib_stylesheets;

	// Global stylesheet
	$return = '<link rel="stylesheet" type="text/css" href="css/global.css">';

	// Default stylesheet
	$return .= '<link rel="stylesheet" type="text/css" href="css/' . htmlentities(TINYIB_DEFAULTSTYLE, ENT_QUOTES) . '.css" title="' . htmlentities($tinyib_stylesheets[TINYIB_DEFAULTSTYLE], ENT_QUOTES) . '" id="mainStylesheet">';

	// Additional stylesheets
	foreach ($tinyib_stylesheets as $filename => $title) {
		if ($filename === TINYIB_DEFAULTSTYLE) {
			continue;
		}
		$return .= '<link rel="alternate stylesheet" type="text/css" href="css/' . htmlentities($filename, ENT_QUOTES) . '.css" title="' . htmlentities($title, ENT_QUOTES) . '">';
	}

	return $return;
}

function pageFooter() {
	
    return <<<EOF
		<div class="footer">
			- <a href="https://4chess.com" target="_blank">com</a> + <a href="https://4chess.net" target="_blank">net</a> + <a href="https://4chess.com" target="_blank">org</a> -
		</div>
	</body>
</html>
EOF;
}

function supportedFileTypes() {
	global $tinyib_uploads;
	if (empty($tinyib_uploads)) {
		return "";
	}

	$types_allowed = array_map('strtoupper', array_unique(array_column($tinyib_uploads, 0)));
	if (count($types_allowed) == 1) {
		return sprintf(__('Supported file type is %s'), $types_allowed[0]);
	}
	$last_type = array_pop($types_allowed);
	return sprintf(__('Supported file types are %1$s and %2$s.'), implode(', ', $types_allowed), $last_type);
}

function _makeLinksClickable($matches) {
	if (!isset($matches[1])) {
		return '';
	}
	$url = cleanQuotes($matches[1]);
	$text = $matches[1];
	return '<a href="' . $url . '" target="_blank">' . $text . '</a>';
}

function makeLinksClickable($text) {
	$text = preg_replace_callback('!(((f|ht)tp(s)?://)[-a-zA-Zа-яА-Я()0-9@%\!_+.,~#?&;:|\'/=]+)!i', '_makeLinksClickable', $text);
	$text = preg_replace('/\(\<a href\=\"(.*)\)"\ target\=\"\_blank\">(.*)\)\<\/a>/i', '(<a href="$1" target="_blank">$2</a>)', $text);
	$text = preg_replace('/\<a href\=\"(.*)\."\ target\=\"\_blank\">(.*)\.\<\/a>/i', '<a href="$1" target="_blank">$2</a>.', $text);
	$text = preg_replace('/\<a href\=\"(.*)\,"\ target\=\"\_blank\">(.*)\,\<\/a>/i', '<a href="$1" target="_blank">$2</a>,', $text);

	return $text;
}

function buildPostForm($parent, $staff_post = false) {
	global $tinyib_hidefieldsop, $tinyib_hidefields, $tinyib_uploads, $tinyib_embeds;
	$hide_fields = $parent == TINYIB_NEWTHREAD ? $tinyib_hidefieldsop : $tinyib_hidefields;

	$postform_extra = array('name' => '', 'email' => '', 'subject' => '', 'footer' => '');
	$input_submit = '<input type="submit" value="' . __('Submit') . '" accesskey="z">';
	if ($staff_post || !in_array('subject', $hide_fields)) {
		$postform_extra['subject'] = $input_submit;
	} else if (!in_array('email', $hide_fields)) {
		$postform_extra['email'] = $input_submit;
	} else if (!in_array('name', $hide_fields)) {
		$postform_extra['name'] = $input_submit;
	} else if (!in_array('email', $hide_fields)) {
		$postform_extra['email'] = $input_submit;
	} else {
		$postform_extra['footer'] = $input_submit;
	}

	$form_action = 'imgboard.php';
	$form_extra = '<input type="hidden" name="parent" value="' . $parent . '">';
	$input_extra = '';
	$rules_extra = '';

	$maxlen_name = (TINYIB_MAXNAME > 0) ? TINYIB_MAXNAME : -1;
	$maxlen_email = (TINYIB_MAXEMAIL > 0) ? TINYIB_MAXEMAIL : -1;
	$maxlen_subject = (TINYIB_MAXSUBJECT > 0) ? TINYIB_MAXSUBJECT : -1;
	$maxlen_message = (TINYIB_MAXMESSAGE > 0) ? TINYIB_MAXMESSAGE : -1;

	if ($staff_post) {
		$txt_raw_html = __('Raw HTML');
		$txt_enable = __('Enable');
		$txt_raw_html_info_1 = __('Text entered in the Message field will be posted as is with no formatting applied.');
		$txt_raw_html_info_2 = __('Line-breaks must be specified with "&lt;br&gt;".');
		$txt_reply_to = __('Reply to');
		$txt_new_thread = __('0 to start a new thread');

		$form_action = '?';
		$form_extra = '<input type="hidden" name="staffpost" value="1">';
		$input_extra = <<<EOF
					<tr>
						<td class="postblock">
							$txt_raw_html
						</td>
						<td>
							<label>
								<input type="checkbox" name="raw" value="1" accesskey="r">&nbsp;$txt_enable<br>
							&nbsp; 	<small>$txt_raw_html_info_1</small><br>
							&nbsp; 	<small>$txt_raw_html_info_2</small>
							</label>
						</td>
					</tr>
					<tr>
						<td class="postblock">
							$txt_reply_to
						</td>
						<td>
							<input type="text" name="parent" size="28" maxlength="75" value="0" accesskey="t">&nbsp;$txt_new_thread
						</td>
					</tr>
EOF;

		$maxlen_name = $maxlen_email = $maxlen_subject = $maxlen_message = -1;
	}

	$max_file_size_input_html = '';
	$max_file_size_rules_html = '';
	$filetypes_html = '';
	$file_input_html = '';
	$embed_input_html = '';
	$unique_posts_html = '';


	// If uploads are allowed
	if (!empty($tinyib_uploads) && ($staff_post || !in_array('file', $hide_fields))) {
		if (TINYIB_MAXKB > 0) {
			$max_file_size_input_html = '<input type="hidden" name="MAX_FILE_SIZE" value="' . strval(TINYIB_MAXKB * 1024) . '">';
			$max_file_size_rules_html = '<li>' . sprintf(__('Maximum file size allowed is %s.'), TINYIB_MAXKBDESC) . '</li>';
		}
		$filetypes_html = '<li>' . supportedFileTypes() . '</li>';

		$txt_file = __('File');
		$file_input_html = <<<EOF
					<tr>
						<td class="postblock">
							$txt_file
						</td>
						<td>
							<input type="file" name="file" size="35" accesskey="f">
						</td>
					</tr>
EOF;
	}

	// Embeds allowed if $tinyib_embeds is not empty
	$embeds_enabled = (!empty($tinyib_embeds) && ($staff_post || !in_array('embed', $hide_fields)));
	if ($embeds_enabled) {
		$txt_embed = __('Embed');
		$txt_embed_help = __('(paste a YouTube URL)');
		$embed_input_html = <<<EOF
					<tr>
						<td class="postblock">
							$txt_embed
						</td>
						<td>
							<input type="text" name="embed" size="28" accesskey="x" autocomplete="off">&nbsp;&nbsp;$txt_embed_help
						</td>
					</tr>
EOF;
	}

	// No TINYIB_REQMOD => so no "All posts are moderated" note:
	$reqmod_html = '';

	// Thumbnails note for images
	$thumbnails_html = '';
	if (isset($tinyib_uploads['image/jpeg']) || isset($tinyib_uploads['image/pjpeg'])
			|| isset($tinyib_uploads['image/png']) || isset($tinyib_uploads['image/gif'])) {
		$maxdimensions = TINYIB_MAXWOP . 'x' . TINYIB_MAXHOP;
		if (TINYIB_MAXW != TINYIB_MAXWOP || TINYIB_MAXH != TINYIB_MAXHOP) {
			$maxdimensions .= ' (new thread) or ' . TINYIB_MAXW . 'x' . TINYIB_MAXH . ' (reply)';
		}
		$thumbnails_html = '<li>' . sprintf(__('Images greater than %s will be thumbnailed.'), $maxdimensions) . '</li>';
	}

	// Show unique posts in rules area
	$unique_posts = uniquePosts();
	if ($unique_posts > 0) {
		$unique_posts_html = '<li>' . sprintf(__('Currently %s unique user posts.'), $unique_posts) . '</li>' . "\n";
	}

	$output = <<<EOF
		<div class="postarea">
			<form name="postform" id="postform" action="$form_action" method="post" enctype="multipart/form-data">
			$max_file_size_input_html
			$form_extra
			<table class="postform">
				<tbody>
					$input_extra
EOF;
	if ($staff_post || !in_array('name', $hide_fields)) {
		$txt_name = __('Name');
		$output .= <<<EOF
					<tr>
						<td class="postblock">
							$txt_name
						</td>
						<td>
							<input type="text" name="name" size="28" maxlength="{$maxlen_name}" accesskey="n">
							{$postform_extra['name']}
						</td>
					</tr>
EOF;
	}
	if ($staff_post || !in_array('email', $hide_fields)) {
		$txt_email = __('E-mail');
		$output .= <<<EOF
					<tr>
						<td class="postblock">
							$txt_email
						</td>
						<td>
							<input type="text" name="email" size="28" maxlength="{$maxlen_email}" accesskey="e">
							{$postform_extra['email']}
						</td>
					</tr>
EOF;
	}
	if ($staff_post || !in_array('subject', $hide_fields)) {
		$txt_subject = __('Subject');
		$output .= <<<EOF
					<tr>
						<td class="postblock">
							$txt_subject
						</td>
						<td>
							<input type="text" name="subject" size="40" maxlength="{$maxlen_subject}" accesskey="s" autocomplete="off">
							{$postform_extra['subject']}
						</td>
					</tr>
EOF;
	}
	if ($staff_post || !in_array('message', $hide_fields)) {
		$txt_message = __('Message');
		$output .= <<<EOF
					<tr>
						<td class="postblock">
							$txt_message
						</td>
						<td>
							<textarea id="message" name="message" cols="48" rows="4" maxlength="{$maxlen_message}" accesskey="m"></textarea>
						</td>
					</tr>
EOF;
	}

	$output .= <<<EOF
					
					$file_input_html
					$embed_input_html
EOF;

	if ($staff_post || !in_array('password', $hide_fields)) {
		$txt_password = __('Password');
		$txt_password_help = __('(for post and file deletion)');
		$output .= <<<EOF
					<tr>
						<td class="postblock">
							$txt_password
						</td>
						<td>
							<input type="password" name="password" id="newpostpassword" size="8" accesskey="p">&nbsp;&nbsp;$txt_password_help
						</td>
					</tr>
EOF;
	}
	if ($postform_extra['footer'] != '') {
		$output .= <<<EOF
					<tr>
						<td>
							&nbsp;
						</td>
						<td>
							{$postform_extra['footer']}
						</td>
					</tr>
EOF;
	}
	$output .= <<<EOF
					<tr>
						<td colspan="2" class="rules">
							$rules_extra
							<ul>
								$reqmod_html
								$filetypes_html
								$max_file_size_rules_html
								$thumbnails_html
								$unique_posts_html
							</ul>
						</td>
					</tr>
				</tbody>
			</table>
			</form>
		</div>
EOF;

	return $output;
}

function buildPost($post, $res, $compact = false) {
	$return = "";
	$threadid = ($post['parent'] == TINYIB_NEWTHREAD) ? $post['id'] : $post['parent'];

	// TINYIB_REPORT = false => no "Report" link:
	$reflink = '';

	// Link to post
	if ($res == TINYIB_RESPAGE) {
		$reflink .= "<a href=\"$threadid.html#{$post['id']}\">No.</a><a href=\"$threadid.html#q{$post['id']}\" onclick=\"javascript:quotePost('{$post['id']}')\">{$post['id']}</a>";
	} else {
		$reflink .= "<a href=\"res/$threadid.html#{$post['id']}\">No.</a><a href=\"res/$threadid.html#q{$post['id']}\">{$post['id']}</a>";
	}

	if ($post["stickied"] == 1) {
		$reflink .= ' <img src="sticky.png" alt="' . __('Stickied') . '" title="' . __('Stickied') . '" width="16" height="16">';
	}
	if ($post["locked"] == 1) {
		$reflink .= ' <img src="lock.png" alt="' . __('Locked') . '" title="' . __('Locked') . '" width="16" height="16">';
	}

	if (!isset($post["omitted"])) {
		$post["omitted"] = 0;
	}

	$filehtml = '';
	$filesize = '';
	$expandhtml = '';
	$direct_link = isEmbed($post["file_hex"]) ? "#" : (($res == TINYIB_RESPAGE ? "../" : "") . "src/" . $post["file"]);

	if ($post['parent'] == TINYIB_NEWTHREAD && $post["file"] != '') {
		$filesize .= (isEmbed($post['file_hex']) ? __('Embed') : __('File')) . ': ';
	}

	$w = TINYIB_EXPANDWIDTH;
	if (isEmbed($post["file_hex"])) {
		$expandhtml = $post['file'];
	} else if (substr($post['file'], -5) == '.webm' || substr($post['file'], -4) == '.mp4') {
		$dimensions = 'width="500" height="50"';
		if ($post['image_width'] > 0 && $post['image_height'] > 0) {
			$dimensions = 'width="' . $post['image_width'] . '" height="' . $post['image_height'] . '"';
		}
		$expandhtml = <<<EOF
<video $dimensions style="position: static; pointer-events: inherit; display: inline; max-width: {$w}vw; height: auto; max-height: 100%;" controls autoplay loop>
	<source src="$direct_link"></source>
</video>
EOF;
	} else if (in_array(substr($post['file'], -4), array('.jpg', '.png', '.gif'))) {
		$expandhtml = "<a href=\"$direct_link\" onclick=\"return expandFile(event, '{$post['id']}');\"><img src=\"" . ($res == TINYIB_RESPAGE ? "../" : "") . "src/{$post["file"]}\" width=\"{$post["image_width"]}\" style=\"min-width: {$post["thumb_width"]}px;min-height: {$post["thumb_height"]}px;max-width: {$w}vw;height: auto;\"></a>";
	}

	$thumblink = "<a href=\"$direct_link\" target=\"_blank\"" . ((isEmbed($post["file_hex"]) || in_array(substr($post['file'], -4), array('.jpg', '.png', '.gif', 'webm', '.mp4'))) ? " onclick=\"return expandFile(event, '{$post['id']}');\"" : "") . ">";

	if (isEmbed($post["file_hex"])) {
		$filesize .= "<a href=\"$direct_link\" onclick=\"return expandFile(event, '{$post['id']}');\">{$post['file_original']}</a>&ndash;({$post['file_hex']})";
	} else if ($post["file"] != '') {
		$filesize .= $thumblink . "{$post["file"]}</a>&ndash;({$post["file_size_formatted"]}";
		if ($post["image_width"] > 0 && $post["image_height"] > 0) {
			$filesize .= ", " . $post["image_width"] . "x" . $post["image_height"];
		}
		if ($post["file_original"] != "") {
			$filesize .= ", " . $post["file_original"];
		}
		$filesize .= ")";
	}

	if ($filesize != '') {
		$filesize = '<span class="filesize">' . $filesize . '</span>';
		if ($post['parent'] != TINYIB_NEWTHREAD) {
			$filehtml .= '<br>';
		}
		$filehtml .= $filesize . '<br><div id="thumbfile' . $post['id'] . '">';
		if ($post["thumb_width"] > 0 && $post["thumb_height"] > 0) {
			$filehtml .= <<<EOF
$thumblink
	<img src="thumb/{$post["thumb"]}" alt="{$post["id"]}" class="thumb" id="thumbnail{$post['id']}" width="{$post["thumb_width"]}" height="{$post["thumb_height"]}">
</a>
EOF;
		}
		$filehtml .= '</div>';

		if ($expandhtml != '') {
			$expandhtml = rawurlencode($expandhtml);
			$filehtml .= <<<EOF
<div id="expand{$post['id']}" style="display: none;">$expandhtml</div>
<div id="file{$post['id']}" class="thumb" style="display: none;"></div>
EOF;
		}
	}

	if ($post['parent'] == TINYIB_NEWTHREAD) {
		$return .= '<div id="post' . $post['id'] . '" class="op">';
		$return .= $filehtml;
	} else {
		if ($compact) {
			$return .= '<div id="' . $post['id'] . '" class="reply">';
		} else {
			$return .= <<<EOF
<table>
<tbody>
<tr>
<td class="doubledash">
	&#0168;
</td>
<td class="reply" id="post{$post["id"]}">
EOF;
		}
	}

	$return .= <<<EOF
<a id="{$post['id']}"></a>
<label>
	<input type="checkbox" name="delete[]" value="{$post['id']}"> 
EOF;

	if ($post['subject'] != '') {
		$return .= ' <span class="filetitle">' . $post['subject'] . '</span> ';
	}

	$return .= <<<EOF
{$post["nameblock"]}
</label>
<span class="reflink">
	$reflink
</span>
EOF;

	if ($post['parent'] == TINYIB_NEWTHREAD) {
		if ($res == TINYIB_INDEXPAGE) {
			$return .= "&nbsp;[<a href=\"res/{$post["id"]}.html\">" . __("Reply") . "</a>]";
		}
	}

	if ($post['parent'] != TINYIB_NEWTHREAD) {
		$return .= $filehtml;
	}

	// Truncate on index pages if TINYIB_TRUNCATE > 0
	if (TINYIB_TRUNCATE > 0 && !$res && _substr_count($post['message'], '<br>') > TINYIB_TRUNCATE) {
		$br_offsets = strallpos($post['message'], '<br>');
		$post['message'] = _substr($post['message'], 0, $br_offsets[TINYIB_TRUNCATE - 1]);
		$post['message'] .= '<br><span class="omittedposts">' . __('Post truncated. Click Reply to view.') . '</span><br>';
	}
	$return .= <<<EOF
<div class="message">
{$post["message"]}
</div>
EOF;

	if ($post['parent'] == TINYIB_NEWTHREAD) {
		$return .= '</div>';
		if ($res == TINYIB_INDEXPAGE && $post['omitted'] > 0) {
			if ($post['omitted'] == 1) {
				$return .= '<span class="omittedposts">' . __('1 post omitted. Click Reply to view.') . '</span>';
			} else {
				$return .= '<span class="omittedposts">' . sprintf(__('%d posts omitted. Click Reply to view.'), $post['omitted']) . '</span>';
			}
		}
	} else if ($compact) {
		$return .= '</div>';
	} else {
		$return .= <<<EOF
</td>
</tr>
</tbody>
</table>
EOF;
	}

	return $return;
}

function buildPage($htmlposts, $parent, $pages = 0, $thispage = 0, $lastpostid = 0) {
	global $tinyib_stylesheets;

	// TINYIB_CATALOG = false => no link
	$cataloglink = '';
	// TINYIB_MANAGEKEY == '' => we still allow the default manage link
	$managelink = (TINYIB_MANAGEKEY == '') ? ('[<a href="' . basename($_SERVER['PHP_SELF']) . '?manage" style="text-decoration: underline;">' . __('Manage') . '</a>]') : '';

	$postingmode = "";
	$pagenavigator = "";

	if ($parent == TINYIB_NEWTHREAD) {
		$pages = max($pages, 0);
		$previous = ($thispage == 1) ? "index" : $thispage - 1;
		$next = $thispage + 1;

		$pagelinks = ($thispage == 0) ? ('<td>' . __('Previous') . '</td>') : ('<td><form method="get" action="' . $previous . '.html"><input value="' . __('Previous') . '" type="submit"></form></td>');
		$pagelinks .= "<td>";
		for ($i = 0; $i <= $pages; $i++) {
			if ($thispage == $i) {
				$pagelinks .= '&#91;' . $i . '&#93; ';
			} else {
				$href = ($i == 0) ? "index" : $i;
				$pagelinks .= '&#91;<a href="' . $href . '.html">' . $i . '</a>&#93; ';
			}
		}
		$pagelinks .= "</td>";
		$pagelinks .= ($pages <= $thispage) ? ('<td>' . __('Next') . '</td>') : ('<td><form method="get" action="' . $next . '.html"><input value="' . __('Next') . '" type="submit"></form></td>');

		$pagenavigator = <<<EOF
<table border="1" style="display: inline-block;">
	<tbody>
		<tr>
			$pagelinks
		</tr>
	</tbody>
</table>
EOF;

	} else if ($parent == -1) {
		$postingmode = '&#91;<a href="index.html">' . __('Return') . '</a>&#93;<div class="replymode">' . __('Catalog') . '</div> ';
	} else {
		$postingmode = '&#91;<a href="../">' . __('Return') . '</a>&#93;<div class="replymode">' . __('Posting mode: Reply') . '</div> ';
	}

	// Show the post form if $parent >= 0
	$postform = ($parent >= 0) ? (buildPostForm($parent) . '<hr>') : '';

	// No TINYIB_AUTOREFRESH => no auto-refresh JS, also no backlinks => no need for that variable:
	$js = '';

	$txt_style = __('Style');
	$txt_password = __('Password');
	$txt_delete = __('Delete');
	$txt_delete_post = __('Delete Post');

	// Let users switch styles if multiple exist
	$select_style = '';
	if (count($tinyib_stylesheets) > 1) {
		$select_style = '<select id="switchStylesheet">';
		$select_style .= '<option value="">' . $txt_style . '</option>';
		foreach($tinyib_stylesheets as $filename => $title) {
			$select_style .= '<option value="' . htmlentities($filename, ENT_QUOTES) . '">'. htmlentities($title) . '</option>';
		}
		$select_style .= '</select>';
	}

	$body = <<<EOF
	<body>
		<div class="adminbar">
			$cataloglink
			$managelink
			$select_style
		</div>
		<div class="logo">
EOF;
	$body .= TINYIB_LOGO . TINYIB_BOARDDESC . <<<EOF
		</div>
		<hr width="90%">
		$postingmode
		$postform
		$js
		<form id="delform" action="imgboard.php?delete" method="post">
		<div id="posts">
		$htmlposts
		</div>
		<hr>
		<table class="userdelete">
			<tbody>
				<tr>
					<td>
						$txt_delete_post <input type="password" name="password" id="deletepostpassword" size="8" placeholder="$txt_password">&nbsp;<input name="deletepost" value="$txt_delete" type="submit">
					</td>
				</tr>
			</tbody>
		</table>
		</form>
		$pagenavigator
		<br>
EOF;
	return pageHeader() . $body . pageFooter();
}

// Removed entire buildCatalogPost() and rebuildCatalog() because TINYIB_CATALOG = false.
// Removed entire buildIndexJSON(), buildCatalogJSON(), buildSingleThreadJSON() because TINYIB_JSON = false.

function rebuildIndexes() {
	$page = 0;
	$i = 0;
	$htmlposts = '';
	$threads = allThreads();
	$pages = ceil(count($threads) / TINYIB_THREADSPERPAGE) - 1;

	foreach ($threads as $thread) {
		$replies = postsInThreadByID($thread['id']);
		$thread['omitted'] = max(0, count($replies) - TINYIB_PREVIEWREPLIES - 1);

		$htmlreplies = array();
		for ($j = count($replies) - 1; $j > $thread['omitted']; $j--) {
			$htmlreplies[] = buildPost($replies[$j], TINYIB_INDEXPAGE);
		}

		if ($i > 0) {
			$htmlposts .= "\n<hr>";
		}
		$htmlposts .= buildPost($thread, TINYIB_INDEXPAGE) . implode('', array_reverse($htmlreplies));

		if (++$i >= TINYIB_THREADSPERPAGE) {
			$file = ($page == 0) ? TINYIB_INDEX : ($page . '.html');
			writePage($file, buildPage($htmlposts, 0, $pages, $page));
			$page++;
			$i = 0;
			$htmlposts = '';
		}
	}

	if ($page == 0 || $htmlposts != '') {
		$file = ($page == 0) ? TINYIB_INDEX : ($page . '.html');
		writePage($file, buildPage($htmlposts, 0, $pages, $page));
	}
}

// TINYIB_REQMOD = '' => no "moderated" checking needed below. 
// We'll still keep rebuildThread for normal usage, but remove the moderation checks.
function rebuildThread($id) {
	$id = intval($id);
	$post = postByID($id);
	if (empty($post)) {
		@unlink('res/' . $id . '.html');
		return;
	}
	$posts = postsInThreadByID($id);
	if (count($posts) == 0) {
		@unlink('res/' . $id . '.html');
		return;
	}
	$htmlposts = "";
	$lastpostid = 0;
	foreach ($posts as $p) {
		$htmlposts .= buildPost($p, TINYIB_RESPAGE);
		$lastpostid = $p['id'];
	}
	writePage('res/' . $id . '.html', fixLinksInRes(buildPage($htmlposts, $id, 0, 0, $lastpostid)));
}

// Management functions

function adminBar() {
	global $account, $loggedin, $isadmin, $returnlink;
	$return = '[<a href="' . $returnlink . '" style="text-decoration: underline;">' . __('Return') . '</a>]';
	if (!$loggedin) {
		return $return;
	}
	$output = '';
	if ($isadmin) {
		if ($account['role'] == TINYIB_SUPER_ADMINISTRATOR) {
			$output .= ' [<a href="?manage&accounts">' . __('Accounts') . '</a>]';
		}
		$output .= ' [<a href="?manage&bans">' . __('Bans') . '</a>]';
		$output .= ' [<a href="?manage&keywords">' . __('Keywords') . '</a>]';
	}
	$output .= ' [<a href="?manage&moderate">' . __('Moderate Post') . '</a>]';
	if ($isadmin) {
		$output .= ' [<a href="?manage&modlog">' . __('Moderation Log') . '</a>]';
		$output .= ' [<a href="?manage&rebuildall">' . __('Rebuild All') . '</a>]';
	}
	// TINYIB_REPORT is false => no "Reports" link
	$output .= ' [<a href="?manage&staffpost">' . __('Staff Post') . '</a>]';
	$output .= ' [<a href="?manage">' . __('Status') . '</a>]';
	$output .= ' &middot;  [<a href="?manage&changepassword">' . __('Change Password') . '</a>]';
	$output .= ' [<a href="?manage&logout">' . __('Log Out') . '</a>]';
	$output .= ' &middot; ' . $return;
	return $output;
}

function managePage($text, $onload = '') {
	$adminbar = adminBar();
	$txt_manage_mode = __('Manage mode');
	$body = <<<EOF
	<body$onload>
		<div class="adminbar">
			$adminbar
		</div>
		<div class="logo">
EOF;
	$body .= TINYIB_LOGO . TINYIB_BOARDDESC . <<<EOF
		</div>
		<hr width="90%">
		<div class="replymode">$txt_manage_mode</div>
		$text
		<hr>
EOF;
	return pageHeader() . $body . pageFooter();
}

function manageOnLoad($page) {
	switch ($page) {
		case 'accounts':
		case 'login':
			return ' onload="document.tinyib.username.focus();"';
		case 'bans':
			return ' onload="document.tinyib.ip.focus();"';
		case 'keywords':
			return ' onload="document.tinyib.text.focus();"';
		case 'moderate':
			return ' onload="document.tinyib.moderate.focus();"';
		case 'staffpost':
			return ' onload="document.tinyib.message.focus();"';
	}
	return '';
}

function manageLogInForm() {
	$txt_login = __('Log In');
	$txt_login_prompt = __('Enter a username and password');



	$managekey = isset($_GET['manage']) ? htmlentities($_GET['manage'], ENT_QUOTES) : '';
	return <<<EOF
	<form id="tinyib" name="tinyib" method="post" action="?manage=$managekey">
	<fieldset>
	<legend align="center">$txt_login_prompt</legend>
	<div class="login">
	<input type="text" id="username" name="username" placeholder="Username"><br>
	<input type="password" id="managepassword" name="managepassword" placeholder="Password"><br>
	
	<input type="submit" value="$txt_login" class="managebutton">
	</div>
	</fieldset>
	</form>
	<br>
EOF;
}

function manageModerationLog($offset) {
	$offset = intval($offset);
	$limit = 50;
	$logs = getLogs($offset, $limit);

	$u = array();
	$text = '';
	foreach ($logs as $log) {
		if (!isset($u[$log['account']])) {
			$username = '';
			if ($log['account'] > 0) {
				$a = accountByID($log['account']);
				if (!empty($a)) {
					$username = $a['username'];
				}
			}
			$u[$log['account']] = $username;
		}
		$text .= '<tr><td>' . formatDate($log['timestamp']) . '</td><td>' . htmlentities($u[$log['account']]) . '</td><td>' . $log['message'] . '</td></tr>';
	}
	if ($text == '') {
		$text = '<i>' . __('No logs.') . '</i>';
	}
	$txt_moderation_log = __('Moderation log');

	$nav = '';
	if ($offset > 0) {
		$nav .= '<a href="?manage&modlog=' . ($offset - $limit) . '">Previous ' . $limit . '</a> ';
	}
	if (count($logs) == $limit) {
		$nav .= '<a href="?manage&modlog=' . ($offset + $limit) . '">Next ' . $limit . '</a> ';
	}
	$nav_top = ($nav != '') ? ($nav . '<br><br>') : '';
	$nav_bottom = ($nav != '') ? ('<br><br>' . $nav) : '';

	return <<<EOF
		$nav_top
		<fieldset>
		<legend>$txt_moderation_log</legend>
		<table border="0" cellspacing="0" cellpadding="0" width="100%">
		<tr><th align="left">Date/time</th><th align="left">Account</th><th align="left">Action</th></tr>
		$text
		</table>
		</fieldset>
		$nav_bottom
EOF;
}

// TINYIB_REPORT = false => manageReportsPage() removed entirely.

// TINYIB_BANMESSAGE = false => no appended ban message block:
function manageBanForm() {
	$txt_ban = __('Add a ban');
	$txt_ban_help = __('Multiple IP addresses may be banned at once by separating each address with a comma.');
	$txt_ban_ip = __('IP Address');
	$txt_ban_expire = __('Expire(sec)');
	$txt_ban_reason = __('Reason');
	$txt_ban_never = __('never');
	$txt_ban_optional = __('Optional.');
	$txt_submit = __('Submit');
	$txt_1h = __('1 hour');
	$txt_1d = __('1 day');
	$txt_2d = __('2 days');
	$txt_1w = __('1 week');
	$txt_2w = __('2 weeks');
	$txt_1m = __('1 month');

	// No extra ban message appended
	$post_ids = isset($_GET['posts']) ? htmlentities($_GET['posts'], ENT_QUOTES) : '';

	return <<<EOF
	<form id="tinyib" name="tinyib" method="post" action="?manage&bans&posts=$post_ids">
	<fieldset>
	<legend>$txt_ban</legend>
	<table border="0">
	<tr><td><label for="ip">$txt_ban_ip</label></td><td><input type="text" name="ip" id="ip" value="{$_GET['bans']}"></td><td><input type="submit" value="$txt_submit" class="managebutton"></td></tr>
	<tr><td><label for="expire">$txt_ban_expire</label></td><td><input type="text" name="expire" id="expire" value="0"></td><td><small><a href="#" onclick="document.tinyib.expire.value='3600';return false;">$txt_1h</a>&nbsp;<a href="#" onclick="document.tinyib.expire.value='86400';return false;">$txt_1d</a>&nbsp;<a href="#" onclick="document.tinyib.expire.value='172800';return false;">$txt_2d</a>&nbsp;<a href="#" onclick="document.tinyib.expire.value='604800';return false;">$txt_1w</a>&nbsp;<a href="#" onclick="document.tinyib.expire.value='1209600';return false;">$txt_2w</a>&nbsp;<a href="#" onclick="document.tinyib.expire.value='2592000';return false;">$txt_1m</a>&nbsp;<a href="#" onclick="document.tinyib.expire.value='0';return false;">$txt_ban_never</a></small></td></tr>
	<tr><td><label for="reason">$txt_ban_reason</label></td><td><input type="text" name="reason" id="reason"></td><td><small>$txt_ban_optional</small></td></tr>
	</table><br>
	<small>$txt_ban_help</small>
	<legend>
	</fieldset>
	</form><br>
EOF;
}

function manageBansTable() {
	$text = '';
	$allbans = allBans();
	if (count($allbans) > 0) {
		$text .= '<table border="1"><tr><th>' . __('IP Address') . '</th><th>' . __('Set At') . '</th><th>' . __('Expires') . '</th><th>' . __('Reason') . '</th><th>&nbsp;</th></tr>';
		foreach ($allbans as $ban) {
			$expire = ($ban['expire'] > 0) ? formatDate($ban['expire']) : __('Does not expire');
			$reason = ($ban['reason'] == '') ? '&nbsp;' : htmlentities($ban['reason']);
			$text .= '<tr><td>' . $ban['ip'] . '</td><td>' . formatDate($ban['timestamp']) . '</td><td>' . $expire . '</td><td>' . $reason . '</td><td><a href="?manage&bans&lift=' . $ban['id'] . '">' . __('lift') . '</a></td></tr>';
		}
		$text .= '</table>';
	}
	return $text;
}

function manageModeratePostForm() {
	$txt_moderate = __('Moderate a post');
	$txt_postid = __('Post ID');
	$txt_submit = __('Submit');
	$txt_tip = __('Tip');
	$txt_tiptext1 = __('While browsing the image board, you can easily moderate a post if you are logged in.');
	$txt_tiptext2 = __('Tick the box next to a post and click "Delete" at the bottom of the page with a blank password.');
	return <<<EOF
	<form id="tinyib" name="tinyib" method="get" action="?">
	<input type="hidden" name="manage" value="">
	<fieldset>
	<legend>$txt_moderate</legend>
	<div valign="top"><label for="moderate">$txt_postid</label> <input type="text" name="moderate" id="moderate"> <input type="submit" value="$txt_submit" class="managebutton"></div><br>
	<b>$txt_tip:</b> $txt_tiptext1<br>
	$txt_tiptext2<br>
	</fieldset>
	</form><br>
EOF;
}

function manageModerateAll($post_ids, $threads, $replies, $ips) {
	global $isadmin;
	$txt_moderate = sprintf(__('Moderate %d posts'), count($post_ids));
	$txt_delete_all = __('Delete all');
	$txt_ban_all = __('Ban all');
	if ($threads == 1 && $replies == 1) {
		$delete_info = __('1 thread and 1 reply will be deleted.');
	} else if ($threads == 1) {
		$delete_info = sprintf(__('1 thread and %d replies will be deleted.'), $replies);
	} else if ($replies == 1) {
		$delete_info = sprintf(__('%d threads and 1 reply will be deleted.'), $threads);
	} else {
		$delete_info = sprintf(__('%1$d threads and %2$d replies will be deleted.'), $threads, $replies);
	}
	if (count($ips) == 1) {
		$ban_info = __('1 IP address will be banned.');
	} else {
		$ban_info = sprintf(__('%d IP addresses will be banned.'), count($ips));
	}
	$ban_disabled = ($isadmin) ? '' : 'disabled';
	$post_ids_quoted = htmlentities(implode(',', $post_ids), ENT_QUOTES);
	$ips_comma = implode(',', $ips);

	return <<<EOF
<table border="0" cellspacing="0" cellpadding="0" width="100%">
<tr><td width="50%">
&nbsp;
</td><td width="50%">

<fieldset>
<legend>$txt_moderate</legend>
<table border="0" cellspacing="0" cellpadding="0" width="100%">
<tr><td>
&nbsp;
</td><td valign="top">

<form method="get" action="?">
<input type="hidden" name="manage" value="">
<input type="hidden" name="delete" value="{$post_ids_quoted}">
<input type="submit" value="$txt_delete_all" class="managebutton">
</form>

</td><td><small>$delete_info</small></td></tr>
<tr><td>
&nbsp;
</td><td valign="top">

<form method="get" action="?">
<input type="hidden" name="manage" value="">
<input type="hidden" name="bans" value="{$ips_comma}">
<input type="hidden" name="posts" value="{$post_ids_quoted}">
<input type="submit" value="$txt_ban_all" class="managebutton" $ban_disabled>
</form>

</td><td><small>$ban_info</small></td></tr>
</table>
</fieldset>

</td></tr>
</table>
EOF;
}

function manageModeratePost($post, $compact = false) {
	global $isadmin;

	$ban = banByIP($post['ip']);
	$ban_disabled = (!$ban && $isadmin) ? '' : ' disabled';
	$ban_info = $ban
		? sprintf(__(' A ban record already exists for %s'), $post['ip'])
		: (!$isadmin ? __('Only an administrator may ban an IP address.') : sprintf(__('IP address: %s'), $post['ip']));

	$thread_or_reply = ($post['parent'] == TINYIB_NEWTHREAD) ? __('Thread') : __('Reply');

	$delete_info = '';
	if ($post['parent'] == TINYIB_NEWTHREAD) {
		$allPosts = postsInThreadByID($post['id']);
		if (count($allPosts) > 1) {
			if (count($allPosts) == 2) {
				$delete_info = __('1 reply will be deleted.');
			} else {
				$delete_info = sprintf(__('%d replies will be deleted.'), count($allPosts) - 1);
			}
		}
	} else {
		$delete_info = sprintf(__('Belongs to %s'), postLink('&gt;&gt;' . $post['id']));
	}

	// Sticky/lock only make sense for threads
	$sticky_html = "";
	$lock_html = "";
	if ($post['parent'] == TINYIB_NEWTHREAD && !$compact) {
		$sticky_set = $post['stickied'] == 1 ? '0' : '1';
		$sticky_unsticky = $post['stickied'] == 1 ? __('Un-sticky') : __('Sticky');
		$sticky_unsticky_help = $post['stickied'] == 1 ? __('Return this thread to a normal state.') : __('Keep this thread at the top of the board.');
		$sticky_html = <<<EOF
	<tr><td>
		<form method="get" action="?">
		<input type="hidden" name="manage" value="">
		<input type="hidden" name="sticky" value="{$post['id']}">
		<input type="hidden" name="setsticky" value="$sticky_set">
		<input type="submit" value="$sticky_unsticky" class="managebutton">
		</form>
	</td><td><small>$sticky_unsticky_help</small></td></tr>
EOF;

		$lock_set = $post['locked'] == 1 ? '0' : '1';
		$lock_label = $post['locked'] == 1 ? __('Unlock') : __('Lock');
		$lock_help = $post['locked'] == 1 ? __('Allow replying to this thread.') : __('Disallow replying to this thread.');
		$lock_html = <<<EOF
	<tr><td>
		<form method="get" action="?">
		<input type="hidden" name="manage" value="">
		<input type="hidden" name="lock" value="{$post['id']}">
		<input type="hidden" name="setlock" value="$lock_set">
		<input type="submit" value="$lock_label" class="managebutton">
		</form>
	</td><td><small>$lock_help</small></td></tr>
EOF;
	}

	$post_html = buildPost($post, TINYIB_INDEXPAGE);
	$txt_moderating = sprintf(__('Moderating No.%d'), $post['id']);
	$txt_action = __('Action');
	$txt_delete = ($post['parent'] == TINYIB_NEWTHREAD) ? __('Delete thread') : __('Delete reply');
	$txt_ban = __('Ban poster');

	return <<<EOF
	<fieldset>
	<legend>$txt_moderating</legend>
	
	<table border="0" cellspacing="0" cellpadding="0" width="100%">
	<tr><td width="50%" valign="top">
	
	<fieldset>
	<legend>$thread_or_reply</legend>	
	$post_html
	</fieldset>
	
	</td><td width="50%" valign="top">
	
	<fieldset>
	<legend>$txt_action</legend>
	
	<table border="0" cellspacing="0" cellpadding="0" width="100%">
	<tr><td>
	
	<form method="get" action="?">
	<input type="hidden" name="manage" value="">
	<input type="hidden" name="delete" value="{$post['id']}">
	<input type="submit" value="$txt_delete" class="managebutton">
	</form>
	
	</td><td><small>$delete_info</small></td></tr>
	<tr><td>
	
	<form method="get" action="?">
	<input type="hidden" name="manage" value="">
	<input type="hidden" name="bans" value="{$post['ip']}">
	<input type="hidden" name="posts" value="{$post['id']}">
	<input type="submit" value="$txt_ban" class="managebutton" $ban_disabled>
	</form>
	
	</td><td><small>$ban_info</small></td></tr>

	$sticky_html
	$lock_html

	</table>
	
	</fieldset>

	</td></tr>
	</table>
	
	</fieldset>
	<br>
EOF;
}

function manageEditKeyword($id) {
	$id = intval($id);
	$v_text = '';
	$v_action = '';
	$v_regexp_checked = '';
	if ($id > 0) {
		$keyword = keywordByID($id);
		if (empty($keyword)) {
			fancyDie(__("Sorry, there doesn't appear to be a keyword with that ID."));
		}
		$v_text = htmlentities($keyword['text'], ENT_QUOTES);
		$v_action = $keyword['action'];
		if (substr($v_text, 0, 7) == 'REGEXP:') {
			$v_regexp_checked = 'checked';
			$v_text = substr($v_text, 7);
		}
	}

	$txt_keyword = __('Keyword');
	$txt_keywords = __('Keywords');
	$txt_action = __('Action');
	$txt_submit = $id > 0 ? __('Update') : __('Add');

	$return = <<<EOF
	<form id="tinyib" name="tinyib" method="post" action="?manage&keywords=$id">
	<fieldset>
	<legend>$txt_keywords</legend>
	<table border="0">
	<tr><td><label for="keyword">$txt_keyword</label></td><td><input type="text" name="text" id="text" value="$v_text"> <label>&nbsp; <input type="checkbox" name="regexp" value="1" $v_regexp_checked> Regular expression</label></td></tr>
	<tr><td><label for="action">$txt_action</label></td><td><select name="action">
EOF;
	// If TINYIB_REPORT is false, we omit the 'report' option
	$return .= '<option value="delete"' . ($v_action == 'delete' ? ' selected' : '') . '>' . __('Delete') . '</option>';
	$return .= '<option value="hide"' . ($v_action == 'hide' ? ' selected' : '') . '>' . __('Hide until approved') . '</option>';
	$return .= '<option value="ban1h"' . ($v_action == 'ban1h' ? ' selected' : '') . '>' . __('Delete and ban for 1 hour') . '</option>';
	$return .= '<option value="ban1d"' . ($v_action == 'ban1d' ? ' selected' : '') . '>' . __('Delete and ban for 1 day') . '</option>';
	$return .= '<option value="ban2d"' . ($v_action == 'ban2d' ? ' selected' : '') . '>' . __('Delete and ban for 2 days') . '</option>';
	$return .= '<option value="ban1w"' . ($v_action == 'ban1w' ? ' selected' : '') . '>' . __('Delete and ban for 1 week') . '</option>';
	$return .= '<option value="ban2w"' . ($v_action == 'ban2w' ? ' selected' : '') . '>' . __('Delete and ban for 2 weeks') . '</option>';
	$return .= '<option value="ban1m"' . ($v_action == 'ban1m' ? ' selected' : '') . '>' . __('Delete and ban for 1 month') . '</option>';
	$return .= '<option value="ban0"' . ($v_action == 'ban0' ? ' selected' : '') . '>' . __('Delete and ban permanently') . '</option>';

	$return .= <<<EOF
	</select></td></tr>
	<tr><td>&nbsp;</td><td><input type="submit" value="$txt_submit" class="managebutton"></td></tr>
	</table>
	</fieldset>
	</form><br>
EOF;
	return $return;
}

function manageKeywordsTable() {
	$text = '';
	$keywords = allKeywords();
	if (count($keywords) > 0) {
		$text .= '<table border="1"><tr><th>' . __('Keyword') . '</th><th>' . __('Action') . '</th><th>&nbsp;</th></tr>';
		foreach ($keywords as $keyword) {
			$action = '';
			switch ($keyword['action']) {
				case 'hide':
					$action = __('Hide until approved');
					break;
				case 'delete':
					$action = __('Delete');
					break;
				case 'ban0':
					$action = __('Delete and ban permanently');
					break;
				case 'ban1h':
					$action = __('Delete and ban for 1 hour');
					break;
				case 'ban1d':
					$action = __('Delete and ban for 1 day');
					break;
				case 'ban2d':
					$action = __('Delete and ban for 2 days');
					break;
				case 'ban1w':
					$action = __('Delete and ban for 1 week');
					break;
				case 'ban2w':
					$action = __('Delete and ban for 2 weeks');
					break;
				case 'ban1m':
					$action = __('Delete and ban for 1 month');
					break;
			}
			$text .= '<tr><td>' . htmlentities($keyword['text']) . '</td><td>' . $action . '</td><td><a href="?manage&keywords=' . $keyword['id'] . '">' . __('Edit') . '</a> <a href="?manage&keywords&deletekeyword=' . $keyword['id'] . '">' . __('Delete') . '</a></td></tr>';
		}
		$text .= '</table>';
	}
	return $text;
}

function manageStatus() {
	global $isadmin;
	$threads = countThreads();
	$bans = count(allBans());

	// TINYIB_REPORT is false => no user-submitted reports
	$info = $threads . ' ' . plural($threads, __('thread'), __('threads')) . ', ' . $bans . ' ' . plural($bans, __('ban'), __('bans'));

	// No TINYIB_REQMOD => no "pending posts" block
	$posts = latestPosts(true);
	$txt_recent_posts = __('Recent posts');

	$post_html = '';
	foreach ($posts as $post) {
		if ($post_html != '') {
			$post_html .= '<tr><td colspan="2"><hr></td></tr>';
		}
		$post_html .= '<tr><td>' . buildPost($post, TINYIB_INDEXPAGE) . '</td><td valign="top" align="right"><form method="get" action="?"><input type="hidden" name="manage" value=""><input type="hidden" name="moderate" value="' . $post['id'] . '"><input type="submit" value="' . __('Moderate') . '" class="managebutton"></form></td></tr>';
	}

	$status_html = <<<EOF
	<fieldset>
	<legend>$txt_recent_posts</legend>
	<table border="0" cellspacing="0" cellpadding="0" width="100%">
		$post_html
	</table>
	</fieldset>
EOF;

	$txt_status = __('Status');
	$txt_info = __('Info');
	return <<<EOF
	<fieldset>
	<legend>$txt_status</legend>
	
	<fieldset>
	<legend>$txt_info</legend>
	<table border="0" cellspacing="0" cellpadding="0" width="100%">
	<tbody>
	<tr><td>
		$info
	</td>
	</tr>
	</tbody>
	</table>
	</fieldset>

	$status_html
	</fieldset>
	<br>
EOF;
}

function manageInfo($text) {
	return '<div class="manageinfo">' . $text . '</div>';
}
