<?php
/**
 * schema.org Product on WooCommerce product pages.
 *
 * Yoast SEO already prints one JSON-LD graph per page (WebPage, BreadcrumbList, WebSite, Organization, ImageObject)
 * but no Product. With Yoast active, a Product node is added to THAT graph (filter wpseo_schema_graph) and the
 * WebPage points to it (mainEntity), so there is one consistent graph. Without Yoast, the same node is printed as
 * one JSON-LD block. WooCommerce's own Product markup is switched off so there is never a second Product.
 *
 * Data: the plugin's products.json (model number, specifications, datasheets, categories, image, URL — the same
 * data the API serves) plus WooCommerce (SKU, stock status) when available. The Offer has availability and a
 * priceSpecification describing "price on request" — Ranatec publishes no prices, so no price value is given.
 */
if (!defined('ABSPATH')) {
    exit;
}

class Ranatec_Agent_Product_Schema
{
    public static function init()
    {
        add_filter('wpseo_schema_graph', [__CLASS__, 'yoast_graph'], 20, 2);
        add_action('wp_head', [__CLASS__, 'standalone'], 40);
        add_filter('woocommerce_structured_data_product', [__CLASS__, 'drop_woocommerce_product'], 99);
    }

    /** The products.json item for the product page being viewed, or null. */
    public static function current_item()
    {
        if (!function_exists('is_singular') || !is_singular('product')) {
            return null;
        }
        $post = get_queried_object();
        return $post && !empty($post->post_name) ? self::item($post->post_name) : null;
    }

    public static function item($slug)
    {
        foreach (Ranatec_Agent_API::load('products')['products'] as $p) {
            if ($p['id'] === $slug) {
                return $p;
            }
        }
        return null;
    }

    /**
     * @param array      $p       products.json item
     * @param array|null $wc      ['sku' => string, 'stock_status' => instock|outofstock|onbackorder] from WooCommerce
     * @param string     $org_id  @id of the Organization node (Yoast) or '' for an inline Organization
     * @param string     $image_id @id of Yoast's primary ImageObject, or '' to use the image URL
     */
    public static function node(array $p, $wc = null, $org_id = '', $image_id = '')
    {
        $names = [];
        foreach (Ranatec_Agent_API::load('categories')['categories'] as $c) {
            $names[$c['id']] = $c['name'];
        }
        $cats = array_values(array_filter(array_map(function ($id) use ($names) { return isset($names[$id]) ? $names[$id] : null; }, isset($p['categories']) ? $p['categories'] : [])));
        $desc = !empty($p['summary']) ? $p['summary'] : (!empty($p['description'][0]) ? $p['description'][0] : $p['name']);
        $org = $org_id !== '' ? ['@id' => $org_id] : ['@type' => 'Organization', 'name' => 'Ranatec AB', 'url' => RANATEC_API_SITE . '/'];
        $sku = $wc && !empty($wc['sku']) ? $wc['sku'] : (!empty($p['model_number']) ? $p['model_number'] : $p['id']);
        $availability = [
            'instock' => 'https://schema.org/InStock',
            'outofstock' => 'https://schema.org/OutOfStock',
            'onbackorder' => 'https://schema.org/BackOrder',
        ];
        $stock = $wc && isset($wc['stock_status'], $availability[$wc['stock_status']]) ? $availability[$wc['stock_status']] : 'https://schema.org/InStock';
        $node = [
            '@type' => 'Product',
            '@id' => $p['url'] . '#product',
            'name' => $p['name'],
            'url' => $p['url'],
            'sku' => $sku,
        ];
        if (!empty($p['model_number'])) {
            $node['mpn'] = $p['model_number'];
            $node['model'] = $p['model_number'];
        }
        $node += [
            'brand' => ['@type' => 'Brand', 'name' => 'Ranatec'],
            'manufacturer' => $org,
            'category' => $cats ? implode(', ', $cats) : null,
            'description' => wp_strip_all_tags($desc),
            'image' => $image_id !== '' ? ['@id' => $image_id] : (!empty($p['image']) ? $p['image'] : null),
            'mainEntityOfPage' => ['@id' => $p['url']],
        ];
        $docs = [];
        foreach (isset($p['datasheets']) ? $p['datasheets'] : [] as $d) {
            $url = is_array($d) ? (isset($d['url']) ? $d['url'] : '') : (string) $d;
            if ($url !== '') {
                $docs[] = ['@type' => 'DigitalDocument', 'name' => 'Datasheet: ' . $p['name'], 'url' => $url, 'encodingFormat' => preg_match('/\.pdf(\?|$)/i', $url) ? 'application/pdf' : null];
            }
        }
        if ($docs) {
            $node['subjectOf'] = array_map('array_filter', $docs);
        }
        $node['offers'] = [
            '@type' => 'Offer',
            'url' => $p['url'],
            'availability' => $stock,
            'priceSpecification' => [
                '@type' => 'PriceSpecification',
                'description' => 'Price on request: Ranatec sells business-to-business by request for quote; no public price.',
            ],
            'seller' => $org,
            'businessFunction' => 'http://purl.org/goodrelations/v1#Sell',
        ];
        return array_filter($node, function ($v) { return $v !== null && $v !== ''; });
    }

    private static function wc_data()
    {
        if (!function_exists('wc_get_product')) {
            return null;
        }
        $wc = wc_get_product(get_queried_object_id());
        return $wc ? ['sku' => (string) $wc->get_sku(), 'stock_status' => (string) $wc->get_stock_status()] : null;
    }

    /** Yoast: add the Product to its graph and make it the WebPage's main entity. */
    public static function yoast_graph($graph, $context = null)
    {
        $p = self::current_item();
        if (!$p || !is_array($graph)) {
            return $graph;
        }
        $org_id = $image_id = '';
        foreach ($graph as $piece) {
            $types = isset($piece['@type']) ? (array) $piece['@type'] : [];
            if (in_array('Organization', $types, true) && !empty($piece['@id'])) {
                $org_id = $piece['@id'];
            }
            if (in_array('ImageObject', $types, true) && !empty($piece['@id']) && substr($piece['@id'], -strlen('#primaryimage')) === '#primaryimage') {
                $image_id = $piece['@id'];
            }
            if (in_array('Product', $types, true)) {
                return $graph;   // something else already describes the product: leave the graph alone
            }
        }
        $node = self::node($p, self::wc_data(), $org_id, $image_id);
        foreach ($graph as &$piece) {
            $types = isset($piece['@type']) ? (array) $piece['@type'] : [];
            if (array_intersect(['WebPage', 'ItemPage'], $types) && empty($piece['mainEntity'])) {
                $piece['mainEntity'] = ['@id' => $node['@id']];
                $node['mainEntityOfPage'] = ['@id' => $piece['@id']];
            }
        }
        unset($piece);
        $graph[] = $node;
        return $graph;
    }

    /** No Yoast: one JSON-LD block with the Product. */
    public static function standalone()
    {
        if (defined('WPSEO_VERSION') || !($p = self::current_item())) {
            return;
        }
        $node = ['@context' => 'https://schema.org'] + self::node($p, self::wc_data());
        echo '<script type="application/ld+json" class="ranatec-product-schema">' . wp_json_encode($node, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "</script>\n";
    }

    /** WooCommerce's Product would be a second, conflicting Product on the same page (it carries a price field). */
    public static function drop_woocommerce_product($markup)
    {
        return self::current_item() ? [] : $markup;
    }
}
