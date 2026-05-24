---
name: create-wordpress-page
description: Create a WordPress page or post end to end — from brief to publish-ready content, in Gutenberg blocks, with draft creation and proofreading.
---

# Create a WordPress page or post

Workflow to cleanly create a new content item through the IA Webmaster
adapter.

## 1. Clarify the brief

Know: the type (`page` or `post`), the title, the goal of the content, the
desired structure, and whether immediate publishing is requested (otherwise:
draft). Also clarify the **target language** if the user wants something
other than the site locale.

## 2. Gather context

- `iawm_content_list` to see existing content — avoid duplicates, align with
  the site's style.
- For a child page: find the parent `id`.

## 3. Draft the content as Gutenberg blocks

Write the body as Gutenberg block markup, for example:

    <!-- wp:heading --><h2>Section title</h2><!-- /wp:heading -->
    <!-- wp:paragraph --><p>Paragraph text.</p><!-- /wp:paragraph -->

The adapter normalises this markup automatically: it does not need to be
perfect. Common blocks: `heading`, `paragraph`, `list`, `image`, `quote`,
`buttons`, `columns`.

## 4. Preview, then create

1. `iawm_content_create` with `dry_run: true` — check what would be created.
2. Real creation: `iawm_content_create` without dry_run. The content is
   created as a **draft**.
3. `iawm_content_get` to re-read and confirm.

When the user has stated a non-default content language, pass `language:
"<bcp47>"` to `iawm_content_create` to make the intent explicit.

## 5. Media (if any)

To add an image: `iawm_media_sideload` (from a URL), always filling the
**alternative text** (`alt`), then reference the media in the content.

## 6. Publication

Publish only if the user asked for it: `iawm_content_update` with
`status: publish`. Otherwise leave it as a draft and share the preview link.
