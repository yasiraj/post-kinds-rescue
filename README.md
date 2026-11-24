# Post Kinds Rescue

Post Kinds Rescue puts posts back in the right kind after you move from the classic Post Kinds plugin to the block version, Post Kinds for IndieWeb.

After a migration, many posts can end up as "article" or "note", even though they were likes, replies, or other kinds before. This tool reads the data the classic plugin saved with each post, works out the kind that data points to, and fixes the kind for you.

## What it does

- Reads the response data the classic plugin saved with each post. For example, a like has the liked URL saved.
- Works out the right kind from that data.
- Shows you a preview first. Nothing changes until you run the fix.
- The kind step changes only the kind. Titles, content, and dates are not touched.
- Saves each post's earlier kind, so every change can be undone.
- Leaves alone posts with more than one kind, and posts with a kind you set on purpose. By default it only looks at posts with no kind, articles, and notes.
- Lists posts it cannot classify safely, and does not change them.
- Step 5 shows the details again. It copies the details the classic plugin saved, such as the liked link, the song, or the book, into the new plugin, and adds the matching card at the top of each post. Your own text stays below the card.

## Before you start

1. Take a backup. WP Engine has one-click backups in the WP Engine User Portal. Make one right before you start.
2. If you can, try the tool on a staging copy of the site first.
3. Make sure Post Kinds for IndieWeb is active. The kinds come from that plugin. Without it, this tool will not run.
4. The tool needs WordPress 6.0 or newer and PHP 7.4 or newer.

## Option A: WP-CLI over SSH

Use this if you can connect to your site over SSH. It is the faster route for large sites, and it does the whole job in one go.

Run every command in the folder that holds your WordPress site. If WP-CLI says it cannot find WordPress, change to that folder first.

### Step 1: Put the plugin on your site

1. Upload the zip file of this plugin to your site. Put it somewhere the server can reach, and note the full path to it.
2. Run this, using the real path to your zip file:

   wp plugin install /path/to/post-kinds-rescue.zip --activate

### Step 2: See what is in the database

1. Run:

   wp pk-rescue scan

2. This lists how many posts each kind has, and how many posts have each kind of old response data. Nothing changes.

### Step 3: Make a preview file

1. Run:

   wp pk-rescue preview --format=csv > preview.csv

2. This writes a file called preview.csv in the folder you are in. It has one row for each post that would change, or that needs a person to check it.
3. In the status column, "change" means the tool will change that post. "needs review" means a person should check it. The tool will not change those.
4. To see the totals, run `wp pk-rescue preview` with no format. The last line gives the count of posts to change and posts that need review.
5. To get the CSV file to your computer, download it with your SFTP program.

### Step 4: Fix the posts

1. Run:

   wp pk-rescue apply

2. It asks "Change the kind of N posts?". Type y and press Enter.
3. The tool saves each post's earlier kind, then changes the kind.
4. Use the same flags here that you used in the preview. If you preview with --skip=photo, add --skip=photo here too. Otherwise the fix will not match the file you checked.
5. Add --yes only after you have read the preview. It skips the question.

### Step 5: Show the details

Do this after Step 4.

1. Look at what will happen. Run:

   wp pk-rescue details preview

2. This lists each post that will get details, and whether it will get a card at the top. Nothing changes.
3. Run:

   wp pk-rescue details apply

4. It asks a question about how many posts will be filled. Type y and press Enter.
5. The tool copies the details into the new plugin's fields. It adds the matching card at the top of each post. Your own text stays below the card.
6. Values that are already filled in are never overwritten.
7. Posts are saved quietly. The last-modified date does not change, and no webmentions or social posts are sent again.

Flags for the details step:

- --meta-only copies the details only. It does not add the card at the top of posts.
- --format=table, csv, or json works with preview only.
- --yes skips the question. It works with apply and undo.

### Step 6: Undo, if you need to

1. To undo the kind changes, run:

   wp pk-rescue undo

2. It asks "Put N posts back to their earlier kind?". Type y and press Enter.
3. Every post the tool changed goes back to the kind it had before. A post you changed by hand after the fix is left as you set it.
4. To undo the details step, run `wp pk-rescue details undo` and type y when asked. See the Undo section below.

### Flags

These work with preview and apply:

- --replace=none,article,note chooses which current kinds the tool may change. "none" means no kind. The default is all three. For example, --replace=article changes only articles.
- --untitled-note turns articles with no title into notes. It only does this for posts with no response data.
- --skip=photo,video leaves those kinds alone, so the tool will not set them. Use kind names, for example photo, video, or note.
- --format=table, csv, or json works with preview only.
- --yes skips the question. It works with apply and undo.

Example:

   wp pk-rescue preview --replace=article,note --untitled-note --skip=photo --format=csv > preview.csv

## Option B: Tools, then Post Kinds Rescue, in wp-admin

Use this if you cannot use SSH. It is a set of plain forms.

Large sites are fixed in rounds of about 25 seconds. If some posts are left, the message at the top of the page tells you, and you press the same button again.

Open Tools in the admin menu, then choose Post Kinds Rescue. The page has these headings and buttons, in this order:

1. Heading 1: "Post Kinds Rescue". Under it is a short paragraph that explains the tool.
2. Heading 2: "Summary". A list says how many posts were checked and how many can be fixed. It also says if any posts need review, if any kinds are missing from your site, and if any posts were changed earlier and can be undone.
3. Heading 2: "Settings". This has three groups.
   - "Change only posts that have one of these kinds now:" with checkboxes "No kind", "Article", and "Note". All three are ticked by default.
   - "Posts without response data" with the checkbox "Make articles that have no title into notes".
   - "Kinds to fix. Untick any you want to leave alone:" with one checkbox for each kind, for example "Note: 12 posts". Untick a kind to leave it alone.
   Then press the button "Update preview" to see the effect of your settings. Focus moves to the Preview heading, and a message says how many posts can be fixed.
4. Heading 2: "Preview". This is a table with a caption that starts "Planned changes". Its columns are "Post", "Kind now", "New kind", and "Why". Each post title links to the post editor. If nothing will change, the page says "Nothing to fix with these settings." Below the table is the button "Download full preview (CSV file)", which downloads the full list.
5. Heading 2: "Step 1: Fix the kinds". This has the button "Change the kind of up to N posts now", where N is the number of posts. Press it to make the changes. Focus moves to a message at the top of the page, which starts "All done" or, on a large site, "Fixed N posts so far" and says how many are left.
6. Heading 2: "Undo the kind changes". This appears after a fix. It has the button "Undo kind changes". If you changed a post's kind yourself after the fix, undo leaves that post as you set it.
7. Heading 2: "Step 2: Show the details on each post". This appears after the Undo section. It is Step 5 below.
8. Heading 2: "What is in your database". This has two tables, "Posts per kind right now" and "Posts per response data field". They help if something looks wrong.

The Change the kind button always uses the settings on the page as they are when you press it. You do not need to press Update preview first.

### Step 5: Show the details (Option B)

Do this after Step 4, once the kinds are fixed.

1. Find the heading "Step 2: Show the details on each post". Under it is a table called "Details waiting to be added". It has one row for each kind, with two counts: "Posts getting details" and "Posts getting a card".
2. Press the button "Add details to N posts now", where N is the number of posts. On a large site it is "Add details to up to N posts now". The tool works in rounds, so if posts are left, the message at the top of the page says so. Press the same button again.
3. When it is finished, the message says "All done: details added to N posts."
4. If the page says "No posts need details added right now.", there is nothing to do.

The details are copied into the new plugin's fields, and the matching card is added at the top of each post. Your own text stays below the card.

To take the details back out, use the button "Remove added details". It appears in this section after details have been added. See the Undo section below.

## Needs review posts

Some posts only have old-style data, in a field called mf2_cite or response. The tool cannot tell their kind for sure, so it does not change them.

These posts are counted in the Summary. In the CSV file, they have the status "needs review". Each row has the post's web address.

To deal with them:

1. Download the CSV file. Use the download button in Option B, or the preview.csv file from Option A.
2. Find the rows with the status "needs review".
3. Open each post and set its kind yourself in the post editor.

Posts that need review get the details data but no card in Step 5.

## Undo

There are two undo steps. You can run either one on its own.

Undo the kind changes (Step 4):

- Undo puts every post the tool changed back to the kind it had before.
- It only touches posts this tool changed.
- If you changed a post's kind by hand after the fix, that post is left as you set it. The message tells you how many posts were left.
- After an undo, you can run the fix again.
- In wp-admin, use the button "Undo kind changes". In WP-CLI, run `wp pk-rescue undo`.

Undo the details (Step 5):

- In wp-admin, use the button "Remove added details" in the "Step 2: Show the details on each post" section. In WP-CLI, run `wp pk-rescue details undo`.
- The card is removed from the top of each post, and the detail fields the tool filled are put back as they were.
- Each post's text is put back exactly as it was, unless you edited that post after Step 5. In that case its text is left as it is, and the message or command output tells you which posts were left.
- Detail fields you have changed since Step 5 are also left alone.

Both:

- Run undo before you delete the plugin. The tool saves hidden notes on each changed post, and undo needs the plugin to read those notes.
- Undo does not change anything outside the posts this tool changed.

## Known limits

- Event and mood posts have no classic data to copy, so Step 5 adds nothing to them.
- Posts that need review get the details data, but no card.
- On the Stream page, a post that also has body text shows the generic card, not the card for its kind.
- Posts with more than one kind are never changed.
- Posts with a kind you set on purpose, such as "Photo" or "Like", are never changed. The settings only offer No kind, Article and Note.
- Kinds that do not exist on your new site are skipped. The Summary, or the warning from the preview command, tells you which ones.
- Posts with only old-style data need a person to check them.
- This is version 0.1.0. Try it on a copy of your site first, and keep your backup until you are happy with the result.

## If something looks wrong

1. Run `wp pk-rescue scan`, or copy the two tables from "What is in your database" in wp-admin.
2. Send that output in the GitHub issue, so we can see what your site has.

## License

GPL-2.0-or-later
