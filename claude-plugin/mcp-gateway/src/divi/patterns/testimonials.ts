/**
 * Testimonials pattern — social proof.
 *
 * Two variants:
 *   - "grid" (default): an adaptive grid of 1, 2 or 3 columns with the
 *     native Divi `testimonial` module per item. Items beyond 3 wrap
 *     onto additional rows.
 *   - "carousel": a single Divi `slider` whose slides each contain
 *     one testimonial (portrait + blockquote). Picked when the
 *     operator wants a single-pane social-proof story rather than a
 *     visible grid — useful on tight pages or when many testimonials
 *     would clutter the layout.
 *
 * Both variants share the same input schema (`TestimonialsOptions`);
 * the `variant` field is the only switch. Defaults to "grid" so
 * existing callers stay unchanged.
 */

import { section, row, column, text, testimonial, slider } from "../builders.js";
import type { SlideOptions } from "../builders.js";
import { colors } from "../globals.js";
import type { DiviColor, GutenbergBlock } from "../types.js";

export interface TestimonialItem {
  quoteHtml: string;
  author: string;
  portraitUrl?: string;
}

export interface TestimonialsOptions {
  /** Layout variant. Default: "grid". */
  variant?: "grid" | "carousel";
  sectionTitle?: string;
  sectionSubtitle?: string;
  items: TestimonialItem[];
  backgroundColor?: DiviColor;
}

export function testimonials(options: TestimonialsOptions): GutenbergBlock {
  const variant = options.variant ?? "grid";
  return variant === "carousel"
    ? testimonialsCarousel(options)
    : testimonialsGrid(options);
}

/* ------------------------------------------------------------------ */
/* Variant 1 — grid                                                   */
/* ------------------------------------------------------------------ */

function testimonialsGrid(options: TestimonialsOptions): GutenbergBlock {
  const rows: GutenbergBlock[] = [];

  if (options.sectionTitle || options.sectionSubtitle) {
    rows.push(sectionHeader(options));
  }

  // Adaptive layout: 1, 2 or 3 columns depending on the item count.
  const count = options.items.length;
  const structure = count === 1 ? "4_4" : count === 2 ? "1_2,1_2" : "1_3,1_3,1_3";
  const colType = count === 1 ? "4_4" : count === 2 ? "1_2" : "1_3";

  // If > 3, group items by 3.
  const groups: TestimonialItem[][] = [];
  if (count <= 3) {
    groups.push(options.items);
  } else {
    for (let i = 0; i < count; i += 3) {
      groups.push(options.items.slice(i, i + 3));
    }
  }

  for (const group of groups) {
    rows.push(
      row(
        { columnStructure: structure, flexWrapMobile: "wrap" },
        group.map((item) => column({ type: colType }, [testimonial(item)])),
      ),
    );
  }

  return wrapInSection(options, rows);
}

/* ------------------------------------------------------------------ */
/* Variant 2 — carousel                                               */
/* ------------------------------------------------------------------ */

/**
 * Carousel variant: the testimonials live inside a single Divi
 * `slider` module. Each slide carries one testimonial — author as
 * the slide title, quote + portrait composed into the slide content
 * HTML so the round-trip preserves the visual structure.
 *
 * Why not nest a `testimonial` module inside each slide? Divi 5
 * slides have a fixed schema (title / content / button); they are
 * not generic containers. Composing the testimonial into the slide
 * content HTML is therefore the native, round-trip-safe path.
 */
function testimonialsCarousel(options: TestimonialsOptions): GutenbergBlock {
  const rows: GutenbergBlock[] = [];

  if (options.sectionTitle || options.sectionSubtitle) {
    rows.push(sectionHeader(options));
  }

  if (options.items.length > 0) {
    const slides: SlideOptions[] = options.items.map((item) => ({
      title: item.author,
      contentHtml: composeSlideContent(item),
    }));

    rows.push(
      row(
        { columnStructure: "4_4" },
        [column({ type: "4_4" }, [slider(slides)])],
      ),
    );
  }

  return wrapInSection(options, rows);
}

/**
 * Build the inner HTML of one slide: optional portrait image,
 * then the quote inside a <blockquote>. The portrait is rendered
 * as a centred round avatar via inline styling so the layout works
 * without theme CSS support; sites that want their own portrait
 * styling can override `.iawm-testimonial-portrait` in their CSS.
 */
function composeSlideContent(item: TestimonialItem): string {
  const portrait = item.portraitUrl
    ? `<p style="text-align:center;margin:0 0 16px;"><img class="iawm-testimonial-portrait" src="${escapeAttr(item.portraitUrl)}" alt="${escapeAttr(item.author)}" style="border-radius:50%;width:96px;height:96px;object-fit:cover;display:inline-block;" /></p>`
    : "";
  // quoteHtml is HTML by contract (same as in the grid variant), so
  // it is not escaped — only wrapped to give Divi a stable hook for
  // typography.
  return `${portrait}<blockquote>${item.quoteHtml}</blockquote>`;
}

/* ------------------------------------------------------------------ */
/* Shared helpers                                                     */
/* ------------------------------------------------------------------ */

function sectionHeader(options: TestimonialsOptions): GutenbergBlock {
  return row(
    { columnStructure: "4_4" },
    [
      column(
        { type: "4_4" },
        [
          text({
            html:
              (options.sectionTitle ? `<h2>${escapeHtml(options.sectionTitle)}</h2>` : "") +
              (options.sectionSubtitle ? `<p>${escapeHtml(options.sectionSubtitle)}</p>` : ""),
            headingFont: {
              h2: { textAlign: "center", size: "36px", weight: "700" },
            },
          }),
        ],
      ),
    ],
  );
}

function wrapInSection(options: TestimonialsOptions, rows: GutenbergBlock[]): GutenbergBlock {
  return section(
    {
      backgroundColor: options.backgroundColor ?? colors.body,
      spacing: {
        padding: { top: "80px", bottom: "80px", syncVertical: "off", syncHorizontal: "off" },
      },
    },
    rows,
  );
}

function escapeHtml(s: string): string {
  return s
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;");
}

function escapeAttr(s: string): string {
  // HTML attribute values: same as text but also escape single quote
  // for defence in depth in case an attribute is single-quoted upstream.
  return escapeHtml(s).replace(/'/g, "&#39;");
}
