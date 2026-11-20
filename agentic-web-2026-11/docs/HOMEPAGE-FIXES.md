# Homepage fixes (WordPress admin)

The homepage heading, its text blocks, the main menu and the images live in WordPress itself: the Avada page
builder, Appearance → Menus and the Media Library. They are not part of the plugin, so they are fixed once in the
admin, at the source. ranatec.com has three homepages (en-US `/`, en-GB `/en-gb/`, en-CA `/en-ca/`, WPML
translations). Steps 1 and 2 apply to **each of them**.

Found on 20 November 2026 (all three homepages): "EQUIPEMENT" 3× (heading + 2 meta tags), 1 visible block of
instructions for AI agents (also repeated in a meta tag), 4 `href="#"` links, and 47 of 72 images without alt text.

## 1. Fix the heading typo "EQUIPEMENT" → "EQUIPMENT"
1. **Pages → Home → Edit with Avada Builder** (Avada Live or the back-end builder).
2. Open the Title element **RF TEST AND MEASUREMENT EQUIPEMENT**, correct it to **RF TEST AND MEASUREMENT EQUIPMENT** and save
   the page (**Update**).
3. Repeat on the en-GB and en-CA homepages (WPML: the language switcher at the top of the page list).

The two meta tags that also contain the typo are generated from this text (see step 3), so they follow
automatically.

## 2. Remove the agent-instruction block from the homepage
1. In the same builder view, find the text element that starts **"AI agents and LLMs should use our structured agent
   layer at https://ranatec.com/agent…"** (a column in the dark section).
2. Delete the element (or its column, if the column holds nothing else) and **Update** the page.
3. Repeat on the en-GB and en-CA homepages.

The footer link **For AI agents → /agent/** stays: it is a link, not an instruction, and it is how crawlers find the
agent page. Agents get the same facts, written as descriptions, from `/agent/`, `/llms.txt` and `/ai.txt`.

## 3. The second meta description
The homepage prints two `<meta name="description">` tags: Yoast's ("Ranatec supplies unique RF test and measurement
equipment…") and a second one built from the first words of the page ("RF TEST AND MEASUREMENT EQUIPEMENT Make your
ideas real… AI agents and LLMs should use…"). It appears right after the oEmbed links, which is where the theme prints
its own meta tags.
- After steps 1 and 2 its text is correct and free of agent instructions.
- To remove the duplicate: in **Avada → Options**, search for "meta" and switch off Avada's own meta tags (Yoast
  already prints the description). The option's name differs between Avada versions. If you can't find it, tell us
  and we will add a filter to the plugin that removes the duplicate tag.

Check: `curl -s https://ranatec.com/ | grep -c '<meta name="description"'` → `1` once removed.

## 4. The four `href="#"` links
| Link | What it is | Fix |
|---|---|---|
| **Products** (main menu) | Custom menu link `#` that opens the product mega menu | **Appearance → Menus** → main menu → *Products* → URL `https://ranatec.com/shop/` (the full catalogue). The mega menu still opens on hover; a click now goes to the shop. |
| **RFQ** (main menu) | Custom menu link `#` with the RFQ mini-widget | Same place → *RFQ* → URL `https://ranatec.com/request-quote/` |
| "Page load link" | Avada's hidden skip link (`aria-hidden`, `tabindex=-1`) | Theme code — leave it |
| Back-to-top arrow (`#toTop`) | Avada's scroll-to-top button | Theme code — leave it |

Do the same in the en-GB and en-CA menus (WPML: **Appearance → Menus**, language switcher), with `/en-gb/…` and
`/en-ca/…` URLs.

## 5. Alt text for the homepage images
1. **Tools → Ranatec Agent API → Image alt text on the homepage → Scan the homepage.**
2. The table lists every homepage image without alt text, with proposed text. All 47 images found on
   20 November 2026 are product or category tiles, so each is proposed with the name of the product or category it
   links to (for example "Butler Matrix RI 3101"). Edit any proposal; leave a field empty for a purely decorative
   image (it keeps `alt=""`).
3. **Save alt text.** The text is stored as each image's own alt text in the Media Library, so the menu, the page
   builder and every other place the image appears use it. Existing alt text is never overwritten.
4. **Scan the homepage** again: images still listed have an empty alt set inside a page-builder element, and the table
   says so. Fix those in the builder.

Check: `curl -s https://ranatec.com/ | grep -o '<img[^>]*>' | grep -c 'alt=""'` (only decorative images remain).

## After the changes
**LiteSpeed Cache → Toolbox → Purge All**, then:
```bash
curl -s https://ranatec.com/ | grep -c EQUIPEMENT                    # 0
curl -s https://ranatec.com/ | grep -c 'AI agents and LLMs should'   # 0
curl -s https://ranatec.com/ | grep -c 'href="#"'                     # 2 (Avada's own skip link and back-to-top)
```
