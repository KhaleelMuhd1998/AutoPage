<?php
/**
 * Plugin Name: AutoPage
 * Plugin URI: https://github.com/KhaleelMuhd1998/AutoPage
 * Description: Creates virtual child URLs for selected WordPress pages and renders typed dynamic content through [autopage] shortcodes.
 * Version: 0.2.0
 * Author: Khaleel M
 * Author URI: https://khaleelmuhd1998.github.io/
 * Requires at least: 7.1
 * Requires PHP: 8.5
 * License: GPL-2.0-or-later
 * Text Domain: autopage
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class AutoPage_Plugin {
    private const VERSION = '0.2.0';
    private const QUERY_VAR = 'autopage_slug';
    private const MENU_SLUG = 'autopage';
    private const PER_PAGE = 20;

    /** @var string[] */
    private const CONTENT_TYPES = array( 'rich_text', 'image', 'external_video', 'embed_url', 'button' );

    /** @var string[] */
    private const STATUSES = array( 'active', 'inactive' );

    public static function init(): void {
        add_action( 'init', array( __CLASS__, 'register_shortcodes' ) );
        add_action( 'init', array( __CLASS__, 'register_rewrite_rules' ), 20 );
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_frontend_assets' ) );

        add_filter( 'query_vars', array( __CLASS__, 'register_query_vars' ) );
        add_filter( 'redirect_canonical', array( __CLASS__, 'prevent_virtual_url_canonical_redirect' ), 10, 2 );
        add_action( 'template_redirect', array( __CLASS__, 'validate_virtual_request' ), 1 );

        if ( is_admin() ) {
            add_action( 'admin_menu', array( __CLASS__, 'register_admin_page' ) );
            add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_assets' ) );
            add_action( 'admin_post_autopage_save_record', array( __CLASS__, 'save_record' ) );
            add_action( 'admin_post_autopage_delete_record', array( __CLASS__, 'delete_record' ) );
            add_action( 'admin_post_autopage_duplicate_record', array( __CLASS__, 'duplicate_record' ) );
            add_action( 'admin_post_autopage_export_csv', array( __CLASS__, 'export_csv' ) );
            add_action( 'admin_post_autopage_download_sample_csv', array( __CLASS__, 'download_sample_csv' ) );
            add_action( 'admin_post_autopage_import_csv', array( __CLASS__, 'import_csv' ) );
        }
    }

    private static function table_name(): string {
        global $wpdb;
        return $wpdb->prefix . 'autopage_content';
    }

    private static function create_database_table(): void {
        global $wpdb;

        $table = self::table_name();
        $charset_collate = $wpdb->get_charset_collate();

        // This is intentionally creation-only. AutoPage 0.2.0 has no migration/versioning layer.
        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            title varchar(255) NOT NULL DEFAULT '',
            base_page_id bigint(20) unsigned NOT NULL,
            slug varchar(200) NOT NULL DEFAULT '',
            content_id varchar(191) NOT NULL,
            type varchar(32) NOT NULL,
            data longtext NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'active',
            author_id bigint(20) unsigned NOT NULL,
            record_key char(64) NOT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY record_key (record_key),
            KEY route_lookup (base_page_id, slug(100), status),
            KEY content_lookup (base_page_id, slug(100), content_id(100), status),
            KEY title_lookup (title(191)),
            KEY author_lookup (author_id),
            KEY status_lookup (status),
            KEY created_lookup (created_at)
        ) {$charset_collate}";

        $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }

    private static function record_key( int $base_page_id, string $slug, string $content_id ): string {
        return hash( 'sha256', $base_page_id . '|' . $slug . '|' . $content_id );
    }

    /** @return array<string,string> */
    public static function content_type_labels(): array {
        return array(
            'rich_text'      => 'Rich Text',
            'image'          => 'Image',
            'external_video' => 'External Video',
            'embed_url'      => 'Embed URL',
            'button'         => 'Button',
        );
    }

    /** @return array<string,string> */
    public static function status_labels(): array {
        return array(
            'active'   => 'Active',
            'inactive' => 'Inactive',
        );
    }

    public static function register_shortcodes(): void {
        add_shortcode( 'autopage', array( __CLASS__, 'render_shortcode' ) );
        add_shortcode( 'AutoPage', array( __CLASS__, 'render_shortcode' ) );
    }

    /** @param string[] $vars @return string[] */
    public static function register_query_vars( array $vars ): array {
        $vars[] = self::QUERY_VAR;
        return $vars;
    }

    public static function register_rewrite_rules(): void {
        foreach ( self::get_virtual_routes() as $route ) {
            $base_page_id = (int) $route['base_page_id'];
            $slug = (string) $route['slug'];
            $base_path = self::get_page_relative_path( $base_page_id );

            if ( '' === $base_path || '' === $slug ) {
                continue;
            }

            $virtual_path = trim( $base_path . '/' . $slug, '/' );
            $existing_page = get_page_by_path( $virtual_path, OBJECT, 'page' );

            if ( $existing_page instanceof WP_Post && 'publish' === $existing_page->post_status ) {
                continue;
            }

            $regex = '^' . preg_quote( $virtual_path, '#' ) . '/?$';
            $query = 'index.php?page_id=' . $base_page_id . '&' . self::QUERY_VAR . '=' . rawurlencode( $slug );
            add_rewrite_rule( $regex, $query, 'top' );
        }
    }

    public static function enqueue_frontend_assets(): void {
        $css = '.autopage-image-wrap{max-width:100%;}.autopage-image-wrap img.autopage-image{display:block;max-width:100%;height:auto;}.autopage-embed{position:relative;width:100%;max-width:100%;overflow:hidden;}.autopage-external-video iframe,.autopage-embed-url iframe{display:block;max-width:100%;width:100%;border:0;}.autopage-external-video{aspect-ratio:16/9;}.autopage-external-video iframe{width:100%;height:100%;}.autopage-embed-url iframe{min-height:450px;}';
        wp_register_style( 'autopage-inline', false, array(), self::VERSION );
        wp_enqueue_style( 'autopage-inline' );
        wp_add_inline_style( 'autopage-inline', $css );
    }

    /** @param array<string,mixed> $atts */
    public static function render_shortcode( array $atts ): string {
        $atts = shortcode_atts( array( 'contentid' => '' ), $atts, 'autopage' );
        $content_id = sanitize_key( (string) $atts['contentid'] );
        $slug = sanitize_title( (string) get_query_var( self::QUERY_VAR ) );

        if ( '' === $content_id ) {
            return '';
        }

        $row = self::get_row_for_request( get_queried_object_id(), $slug, $content_id );
        return $row ? self::render_content( $row ) : '';
    }

    /** @param array<string,mixed> $row */
    private static function render_content( array $row ): string {
        if ( 'active' !== (string) ( $row['status'] ?? '' ) ) {
            return '';
        }

        $data = is_array( $row['data'] ) ? $row['data'] : array();

        return match ( (string) $row['type'] ) {
            'rich_text'      => self::render_rich_text( $data ),
            'image'          => self::render_image( $data ),
            'external_video' => self::render_external_video( $data ),
            'embed_url'      => self::render_embed_url( $data ),
            'button'         => self::render_button( $data ),
            default          => '',
        };
    }

    /** @param array<string,mixed> $data */
    private static function render_rich_text( array $data ): string {
        $html = isset( $data['html'] ) ? (string) $data['html'] : '';
        return '' === $html ? '' : '<div class="autopage-rich-text">' . wp_kses_post( $html ) . '</div>';
    }

    /** @param array<string,mixed> $data */
    private static function render_image( array $data ): string {
        $attachment_id = isset( $data['attachment_id'] ) ? absint( $data['attachment_id'] ) : 0;

        if ( ! $attachment_id || 'attachment' !== get_post_type( $attachment_id ) ) {
            return '';
        }

        $image = wp_get_attachment_image(
            $attachment_id,
            'full',
            false,
            array(
                'class'   => 'autopage-image',
                'loading' => 'lazy',
            )
        );

        return $image ? '<div class="autopage-image-wrap">' . $image . '</div>' : '';
    }

    /** @param array<string,mixed> $data */
    private static function render_external_video( array $data ): string {
        $url = isset( $data['url'] ) ? esc_url( (string) $data['url'] ) : '';

        if ( '' === $url ) {
            return '';
        }

        $oembed = wp_oembed_get( $url );

        if ( ! is_string( $oembed ) || '' === $oembed ) {
            return '';
        }

        $allowed = array(
            'iframe' => array(
                'src' => true,
                'width' => true,
                'height' => true,
                'frameborder' => true,
                'allow' => true,
                'allowfullscreen' => true,
                'loading' => true,
                'referrerpolicy' => true,
                'title' => true,
                'class' => true,
            ),
        );

        return '<div class="autopage-embed autopage-external-video">' . wp_kses( $oembed, $allowed ) . '</div>';
    }

    /** @param array<string,mixed> $data */
    private static function render_embed_url( array $data ): string {
        $url = isset( $data['url'] ) ? esc_url( (string) $data['url'] ) : '';
        $title = isset( $data['title'] ) ? sanitize_text_field( (string) $data['title'] ) : '';

        if ( '' === $url ) {
            return '';
        }

        return sprintf(
            '<div class="autopage-embed autopage-embed-url"><iframe src="%1$s" title="%2$s" loading="lazy" referrerpolicy="strict-origin-when-cross-origin" allowfullscreen></iframe></div>',
            esc_url( $url ),
            esc_attr( $title )
        );
    }

    /** @param array<string,mixed> $data */
    private static function render_button( array $data ): string {
        $label = isset( $data['label'] ) ? sanitize_text_field( (string) $data['label'] ) : '';
        $url = isset( $data['url'] ) ? esc_url( (string) $data['url'] ) : '';
        $new_tab = ! empty( $data['new_tab'] );

        if ( '' === $label || '' === $url ) {
            return '';
        }

        $target = $new_tab ? ' target="_blank" rel="noopener noreferrer"' : '';
        return sprintf( '<a class="autopage-button wp-element-button" href="%1$s"%2$s>%3$s</a>', esc_url( $url ), $target, esc_html( $label ) );
    }

    public static function prevent_virtual_url_canonical_redirect( string|false $redirect_url, string $requested_url ): string|false {
        unset( $requested_url );
        return get_query_var( self::QUERY_VAR ) ? false : $redirect_url;
    }

    public static function validate_virtual_request(): void {
        $slug = sanitize_title( (string) get_query_var( self::QUERY_VAR ) );

        if ( '' === $slug ) {
            return;
        }

        if ( self::virtual_slug_exists( get_queried_object_id(), $slug ) ) {
            return;
        }

        global $wp_query;
        if ( $wp_query instanceof WP_Query ) {
            $wp_query->set_404();
        }
        status_header( 404 );
        nocache_headers();
    }

    private static function virtual_slug_exists( int $base_page_id, string $slug ): bool {
        global $wpdb;
        $table = self::table_name();

        return (bool) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT 1 FROM {$table} WHERE base_page_id = %d AND slug = %s AND status = 'active' LIMIT 1",
                $base_page_id,
                $slug
            )
        );
    }

    public static function register_admin_page(): void {
        add_menu_page(
            'AutoPage',
            'AutoPage',
            'manage_options',
            self::MENU_SLUG,
            array( __CLASS__, 'render_admin_page' ),
            'dashicons-admin-page',
            58
        );
    }

    public static function enqueue_admin_assets( string $hook_suffix ): void {
        if ( 'toplevel_page_' . self::MENU_SLUG !== $hook_suffix ) {
            return;
        }

        wp_enqueue_media();
        wp_enqueue_editor();
    }

    public static function render_admin_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        if ( ! class_exists( 'AutoPage_List_Table' ) ) {
            return;
        }

        $list_table = new AutoPage_List_Table();
        $list_table->prepare_items();
        $records = $list_table->editor_records();
        $pages = get_pages(
            array(
                'post_status' => 'publish',
                'sort_column' => 'post_title',
                'sort_order'  => 'ASC',
            )
        );

        $notice = isset( $_GET['autopage_notice'] ) ? sanitize_key( (string) wp_unslash( $_GET['autopage_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $imported = isset( $_GET['imported'] ) ? absint( $_GET['imported'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">AutoPage</h1>
            <a href="#" class="page-title-action" id="autopage-add-content">Add Content</a>
            <hr class="wp-header-end">

            <?php self::render_admin_notice( $notice, $imported ); ?>

            <hr class="autopage-section-separator">

            <div class="autopage-section autopage-file-tools">
                <p>
                    <a href="#" id="autopage-import-file-link">Import File</a>
                    <span aria-hidden="true"> | </span>
                    <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=autopage_export_csv' ), 'autopage_export_csv' ) ); ?>">Export File</a>
                    <span aria-hidden="true"> | </span>
                    <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=autopage_download_sample_csv' ), 'autopage_download_sample_csv' ) ); ?>">Download Sample File</a>
                </p>
                <p class="description"><strong>Caution:</strong> <code>base_page + slug + content_id</code> are the unique identifiers. If an imported row matches an existing record, AutoPage updates that record instead of creating a duplicate.</p>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" id="autopage-import-form" hidden>
                    <input type="hidden" name="action" value="autopage_import_csv">
                    <?php wp_nonce_field( 'autopage_import_csv' ); ?>
                    <input type="file" id="autopage-csv" name="autopage_csv" accept=".csv,text/csv" required>
                </form>
            </div>

            <hr class="autopage-section-separator">

            <div class="autopage-section autopage-contents">
                <form method="post">
                    <input type="hidden" name="page" value="<?php echo esc_attr( self::MENU_SLUG ); ?>">
                    <?php wp_nonce_field( 'autopage_bulk_action', 'autopage_bulk_nonce' ); ?>
                    <?php
                    $list_table->search_box( 'Search Contents', 'autopage-content' );
                    $list_table->views();
                    $list_table->display();
                    ?>
                </form>
            </div>

            <div id="autopage-modal" class="autopage-modal" hidden>
                <div class="autopage-modal-backdrop" data-autopage-close></div>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="autopage-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="autopage-modal-title">
                    <input type="hidden" name="action" value="autopage_save_record">
                    <input type="hidden" name="record_id" id="autopage-record-id" value="0">
                    <?php wp_nonce_field( 'autopage_save_record', 'autopage_nonce' ); ?>

                    <div class="autopage-modal-header">
                        <h2 id="autopage-modal-title">Add Content</h2>
                        <button type="button" class="button-link autopage-modal-x" data-autopage-close aria-label="Close" style="text-decoration: none;">×</button>
                    </div>

                    <div class="autopage-modal-body">
                        <table class="form-table" role="presentation">
                            <tr>
                                <th scope="row"><label for="autopage-title">Title</label></th>
                                <td><input type="text" id="autopage-title" name="title" class="regular-text" required></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="autopage-base-page">Base Page</label></th>
                                <td>
                                    <select id="autopage-base-page" name="base_page_id" required>
                                        <option value="">Select page</option>
                                        <?php foreach ( $pages as $page ) : ?>
                                            <option value="<?php echo esc_attr( (string) $page->ID ); ?>"><?php echo esc_html( $page->post_title . ' — ' . wp_make_link_relative( get_permalink( $page ) ) ); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="autopage-slug">URL Slug</label></th>
                                <td><input type="text" id="autopage-slug" name="slug" class="regular-text" placeholder="Base page"><p class="description">Leave empty to use the base page itself.</p></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="autopage-content-id">Content ID</label></th>
                                <td><input type="text" id="autopage-content-id" name="content_id" class="regular-text" placeholder="sample_content" required></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="autopage-type">Type</label></th>
                                <td>
                                    <select id="autopage-type" name="type">
                                        <?php foreach ( self::content_type_labels() as $value => $label ) : ?>
                                            <option value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $label ); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="autopage-status">Status</label></th>
                                <td>
                                    <select id="autopage-status" name="status">
                                        <option value="active">Active</option>
                                        <option value="inactive">Inactive</option>
                                    </select>
                                </td>
                            </tr>
                            <tr class="autopage-existing-meta" hidden>
                                <th scope="row">Author</th>
                                <td id="autopage-author-display">—</td>
                            </tr>
                            <tr class="autopage-existing-meta" hidden>
                                <th scope="row">Date and Time</th>
                                <td id="autopage-created-display">—</td>
                            </tr>
                        </table>

                        <hr>

                        <div class="autopage-content-field" data-editor-type="rich_text">
                            <h3>Rich Text</h3>
                            <textarea id="autopage-modal-rich" name="rich_text" rows="14"></textarea>
                        </div>

                        <div class="autopage-content-field" data-editor-type="image" hidden>
                            <h3>Image</h3>
                            <input type="hidden" id="autopage-image-id" name="attachment_id">
                            <div id="autopage-image-preview"></div>
                            <p><button type="button" class="button" id="autopage-select-image">Select image</button> <button type="button" class="button" id="autopage-remove-image">Remove image</button></p>
                        </div>

                        <div class="autopage-content-field" data-editor-type="external_video" hidden>
                            <h3>External Video</h3>
                            <label for="autopage-video-url"><strong>Video URL</strong></label>
                            <input type="url" id="autopage-video-url" name="external_video_url" class="large-text" placeholder="https://www.youtube.com/watch?v=...">
                            <p class="description">Uses WordPress oEmbed for supported external video providers (YouTube or Vimeo).</p>
                        </div>

                        <div class="autopage-content-field" data-editor-type="embed_url" hidden>
                            <h3>Embed URL</h3>
                            <p><label for="autopage-embed-url"><strong>iframe Source URL</strong></label><input type="url" id="autopage-embed-url" name="embed_url" class="large-text" placeholder="https://www.google.com/maps/embed?pb=..."></p>
                            <p><label for="autopage-embed-title"><strong>Accessible title</strong></label><input type="text" id="autopage-embed-title" name="embed_title" class="large-text" placeholder="Location map"></p>
                            <p class="description">Enter the iframe <code>src</code> URL, not complete iframe HTML.</p>
                        </div>

                        <div class="autopage-content-field" data-editor-type="button" hidden>
                            <h3>Button</h3>
                            <p><label for="autopage-button-label"><strong>Button text</strong></label><input type="text" id="autopage-button-label" name="button_label" class="large-text"></p>
                            <p><label for="autopage-button-url"><strong>Button URL</strong></label><input type="url" id="autopage-button-url" name="button_url" class="large-text"></p>
                            <label><input type="checkbox" id="autopage-button-new-tab" name="button_new_tab" value="1"> Open in a new tab</label>
                        </div>
                    </div>

                    <div class="autopage-modal-footer">
                        <button type="button" class="button" data-autopage-close>Cancel</button>
                        <button type="submit" class="button button-primary">Save Content</button>
                    </div>
                </form>
            </div>

            <hr class="autopage-section-separator">

            <div class="autopage-section autopage-instructions">
                <p><strong>Shortcode example:</strong> <code>[autopage contentid="sample_content"]</code></p>
                <p><strong>Permalink requirement:</strong> Use any WordPress pretty permalink structure. The <em>Plain</em> structure (<code>?p=123</code>) is not supported for AutoPage virtual slug URLs.</p>
                <p><strong>Storage information:</strong> AutoPage stores each content as a row in <code>wp_autopage_content</code>.</p>
            </div>
        </div>

        <style>
            .autopage-section{margin:14px 0}.autopage-section-separator{border:0;border-top:1px solid #dcdcde;margin:18px 0}.autopage-file-tools p{margin:6px 0}.autopage-modal[hidden]{display:none}.autopage-modal{position:fixed;inset:0;z-index:100000}.autopage-modal-backdrop{position:absolute;inset:0;background:rgba(0,0,0,.55)}.autopage-modal-dialog{position:relative;background:#fff;width:min(960px,calc(100vw - 48px));max-height:calc(100vh - 48px);margin:24px auto;display:flex;flex-direction:column;box-shadow:0 12px 40px rgba(0,0,0,.3)}.autopage-modal-header,.autopage-modal-footer{padding:16px 20px;display:flex;align-items:center;justify-content:space-between}.autopage-modal-header{border-bottom:1px solid #dcdcde}.autopage-modal-footer{border-top:1px solid #dcdcde;justify-content:flex-end;gap:8px}.autopage-modal-body{padding:0 20px 20px;overflow:auto}.autopage-modal-x{font-size:30px;text-decoration:none}.autopage-content-field[hidden]{display:none!important}.autopage-content-field input.large-text{width:100%}#autopage-image-preview img{display:block;max-width:100%;max-height:360px;width:auto;height:auto;margin:12px 0}.column-title{width:20%}.column-base_page{width:15%}.column-slug,.column-content_id{width:12%}.column-type,.column-status{width:10%}.column-author{width:9%}.column-date{width:12%}.column-actions{width:15%}.autopage-status-inactive{color:#646970}.autopage-row-actions .delete a{color:#b32d2e}
        </style>

        <script>
        (function(){
            const modal=document.getElementById('autopage-modal');
            const add=document.getElementById('autopage-add-content');
            if(!modal||!add)return;

            const records=<?php echo wp_json_encode( $records, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ); ?>;
            const q=(s,r=document)=>r.querySelector(s);
            const qa=(s,r=document)=>Array.from(r.querySelectorAll(s));
            let editorReady=false;

            function destroyEditor(){
                if(editorReady&&window.wp&&wp.editor){
                    try{wp.editor.remove('autopage-modal-rich')}catch(e){}
                    editorReady=false;
                }
            }

            function initEditor(value){
                const ta=q('#autopage-modal-rich');
                ta.value=value||'';
                if(window.wp&&wp.editor){
                    wp.editor.initialize('autopage-modal-rich',{
                        tinymce:{wpautop:true,toolbar1:'formatselect,bold,italic,bullist,numlist,blockquote,alignleft,aligncenter,alignright,link,unlink,undo,redo',toolbar2:'forecolor,pastetext,removeformat,charmap,outdent,indent'},
                        quicktags:true,
                        mediaButtons:true
                    });
                    editorReady=true;
                }
            }

            function showType(type){
                qa('.autopage-content-field',modal).forEach(el=>el.hidden=el.dataset.editorType!==type);
            }

            function resetForm(){
                destroyEditor();
                q('#autopage-record-id').value='0';
                q('#autopage-title').value='';
                q('#autopage-base-page').value='';
                q('#autopage-slug').value='';
                q('#autopage-content-id').value='';
                q('#autopage-type').value='rich_text';
                q('#autopage-status').value='active';
                q('#autopage-image-id').value='';
                q('#autopage-image-preview').innerHTML='';
                q('#autopage-video-url').value='';
                q('#autopage-embed-url').value='';
                q('#autopage-embed-title').value='';
                q('#autopage-button-label').value='';
                q('#autopage-button-url').value='';
                q('#autopage-button-new-tab').checked=false;
                q('#autopage-author-display').textContent='—';
                q('#autopage-created-display').textContent='—';
                qa('.autopage-existing-meta',modal).forEach(el=>el.hidden=true);
                showType('rich_text');
            }

            function openModal(record){
                resetForm();
                const editing=record&&record.id;
                q('#autopage-modal-title').textContent=editing?'Edit Content':'Add Content';

                if(editing){
                    q('#autopage-record-id').value=record.id;
                    q('#autopage-title').value=record.title||'';
                    q('#autopage-base-page').value=record.base_page_id||'';
                    q('#autopage-slug').value=record.slug||'';
                    q('#autopage-content-id').value=record.content_id||'';
                    q('#autopage-type').value=record.type||'rich_text';
                    q('#autopage-status').value=record.status||'active';
                    q('#autopage-author-display').textContent=record.author_display||'—';
                    q('#autopage-created-display').textContent=record.created_display||'—';
                    qa('.autopage-existing-meta',modal).forEach(el=>el.hidden=false);

                    const data=record.data||{};
                    q('#autopage-image-id').value=data.attachment_id||'';
                    if(record.image_preview)q('#autopage-image-preview').innerHTML='<img src="'+String(record.image_preview).replace(/"/g,'&quot;')+'" alt="">';
                    q('#autopage-video-url').value=data.url&&record.type==='external_video'?data.url:'';
                    q('#autopage-embed-url').value=data.url&&record.type==='embed_url'?data.url:'';
                    q('#autopage-embed-title').value=data.title||'';
                    q('#autopage-button-label').value=data.label||'';
                    q('#autopage-button-url').value=data.url&&record.type==='button'?data.url:'';
                    q('#autopage-button-new-tab').checked=!!data.new_tab;
                    showType(record.type||'rich_text');
                    if(record.type==='rich_text')setTimeout(()=>initEditor(data.html||''),0);
                }else{
                    setTimeout(()=>initEditor(''),0);
                }

                modal.hidden=false;
                document.body.style.overflow='hidden';
            }

            function closeModal(){
                destroyEditor();
                modal.hidden=true;
                document.body.style.overflow='';
            }

            add.addEventListener('click',e=>{e.preventDefault();openModal(null)});
            document.addEventListener('click',e=>{
                const edit=e.target.closest('.autopage-edit-record');
                if(edit){
                    e.preventDefault();
                    openModal(records[String(edit.dataset.id)]||null);
                }
            });
            qa('[data-autopage-close]',modal).forEach(el=>el.addEventListener('click',closeModal));
            q('#autopage-type').addEventListener('change',e=>{
                const type=e.target.value;
                destroyEditor();
                showType(type);
                if(type==='rich_text')setTimeout(()=>initEditor(q('#autopage-modal-rich').value||''),0);
            });
            q('#autopage-select-image').addEventListener('click',()=>{
                const frame=wp.media({title:'Select image',button:{text:'Use image'},multiple:false,library:{type:'image'}});
                frame.on('select',()=>{
                    const a=frame.state().get('selection').first().toJSON();
                    q('#autopage-image-id').value=a.id||'';
                    const src=a.sizes&&a.sizes.medium?a.sizes.medium.url:a.url;
                    q('#autopage-image-preview').innerHTML=src?'<img src="'+src+'" alt="">':'';
                });
                frame.open();
            });
            q('#autopage-remove-image').addEventListener('click',()=>{q('#autopage-image-id').value='';q('#autopage-image-preview').innerHTML=''});
            q('.autopage-modal-dialog').addEventListener('submit',()=>{if(editorReady&&window.tinymce){const ed=tinymce.get('autopage-modal-rich');if(ed)ed.save()}});
            const importLink=document.getElementById('autopage-import-file-link'), importForm=document.getElementById('autopage-import-form'), csvInput=document.getElementById('autopage-csv');
            if(importLink&&importForm&&csvInput){importLink.addEventListener('click',e=>{e.preventDefault();csvInput.click()});csvInput.addEventListener('change',()=>{if(csvInput.files&&csvInput.files.length)importForm.submit()})}
            document.addEventListener('keydown',e=>{if(e.key==='Escape'&&!modal.hidden)closeModal()});
        }());
        </script>
        <?php
    }

    private static function render_admin_notice( string $notice, int $imported ): void {
        if ( 'saved' === $notice ) {
            echo '<div class="notice notice-success is-dismissible"><p>Content saved.</p></div>';
        } elseif ( 'deleted' === $notice ) {
            echo '<div class="notice notice-success is-dismissible"><p>Content removed.</p></div>';
        } elseif ( 'duplicated' === $notice ) {
            echo '<div class="notice notice-success is-dismissible"><p>Content duplicated as inactive.</p></div>';
        } elseif ( 'duplicate_key' === $notice ) {
            echo '<div class="notice notice-error"><p>That Base Page, URL Slug and Content ID combination already exists.</p></div>';
        } elseif ( 'imported' === $notice ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf( '%d content rows imported.', $imported ) ) . '</p></div>';
        } elseif ( 'import_error' === $notice ) {
            echo '<div class="notice notice-error"><p>The CSV file could not be imported. Check the file format and required columns.</p></div>';
        } elseif ( 'invalid' === $notice ) {
            echo '<div class="notice notice-error"><p>The content could not be saved because one or more required fields were invalid.</p></div>';
        }
    }

    /** @return array<string,mixed> */
    private static function sanitize_content_data( string $type, array $source ): array {
        return match ( $type ) {
            'rich_text' => array(
                'html' => isset( $source['rich_text'] ) ? wp_kses_post( (string) $source['rich_text'] ) : '',
            ),
            'image' => array(
                'attachment_id' => isset( $source['attachment_id'] ) ? absint( $source['attachment_id'] ) : 0,
            ),
            'external_video' => array(
                'url' => isset( $source['external_video_url'] ) ? esc_url_raw( (string) $source['external_video_url'] ) : '',
            ),
            'embed_url' => array(
                'url' => isset( $source['embed_url'] ) ? esc_url_raw( (string) $source['embed_url'] ) : '',
                'title' => isset( $source['embed_title'] ) ? sanitize_text_field( (string) $source['embed_title'] ) : '',
            ),
            'button' => array(
                'label' => isset( $source['button_label'] ) ? sanitize_text_field( (string) $source['button_label'] ) : '',
                'url' => isset( $source['button_url'] ) ? esc_url_raw( (string) $source['button_url'] ) : '',
                'new_tab' => isset( $source['button_new_tab'] ) && '1' === (string) $source['button_new_tab'],
            ),
            default => array(),
        };
    }

    public static function save_record(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You are not allowed to manage AutoPage.', 'autopage' ) );
        }

        check_admin_referer( 'autopage_save_record', 'autopage_nonce' );
        $post = wp_unslash( $_POST );

        $id = isset( $post['record_id'] ) ? absint( $post['record_id'] ) : 0;
        $title = isset( $post['title'] ) ? sanitize_text_field( (string) $post['title'] ) : '';
        $base_page_id = isset( $post['base_page_id'] ) ? absint( $post['base_page_id'] ) : 0;
        $slug = isset( $post['slug'] ) ? sanitize_title( (string) $post['slug'] ) : '';
        $content_id = isset( $post['content_id'] ) ? sanitize_key( (string) $post['content_id'] ) : '';
        $type = isset( $post['type'] ) ? sanitize_key( (string) $post['type'] ) : 'rich_text';
        $status = isset( $post['status'] ) ? sanitize_key( (string) $post['status'] ) : 'active';

        if (
            '' === $title ||
            $base_page_id <= 0 ||
            '' === $content_id ||
            ! in_array( $type, self::CONTENT_TYPES, true ) ||
            ! in_array( $status, self::STATUSES, true ) ||
            'page' !== get_post_type( $base_page_id ) ||
            'publish' !== get_post_status( $base_page_id )
        ) {
            wp_safe_redirect( self::admin_url_with( array( 'autopage_notice' => 'invalid' ) ) );
            exit;
        }

        global $wpdb;
        $table = self::table_name();
        $record_key = self::record_key( $base_page_id, $slug, $content_id );
        $conflict_id = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$table} WHERE record_key = %s AND id <> %d LIMIT 1",
                $record_key,
                $id
            )
        );

        if ( $conflict_id > 0 ) {
            wp_safe_redirect( self::admin_url_with( array( 'autopage_notice' => 'duplicate_key' ) ) );
            exit;
        }

        $data = self::sanitize_content_data( $type, $post );
        $encoded = wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        if ( ! is_string( $encoded ) ) {
            $encoded = '{}';
        }

        $now = current_time( 'mysql' );
        $payload = array(
            'title'        => $title,
            'base_page_id' => $base_page_id,
            'slug'         => $slug,
            'content_id'   => $content_id,
            'type'         => $type,
            'data'         => $encoded,
            'status'       => $status,
            'record_key'   => $record_key,
            'updated_at'   => $now,
        );
        $formats = array( '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' );

        if ( $id > 0 ) {
            $wpdb->update( $table, $payload, array( 'id' => $id ), $formats, array( '%d' ) );
        } else {
            $payload['author_id'] = get_current_user_id();
            $payload['created_at'] = $now;
            $formats[] = '%d';
            $formats[] = '%s';
            $wpdb->insert( $table, $payload, $formats );
        }

        flush_rewrite_rules();
        wp_safe_redirect( self::admin_url_with( array( 'autopage_notice' => 'saved' ) ) );
        exit;
    }

    public static function delete_record(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You are not allowed to manage AutoPage.', 'autopage' ) );
        }

        $id = isset( $_GET['record_id'] ) ? absint( $_GET['record_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        check_admin_referer( 'autopage_delete_record_' . $id );

        if ( $id > 0 ) {
            global $wpdb;
            $wpdb->delete( self::table_name(), array( 'id' => $id ), array( '%d' ) );
            flush_rewrite_rules();
        }

        wp_safe_redirect( self::admin_url_with( array( 'autopage_notice' => 'deleted' ) ) );
        exit;
    }

    public static function duplicate_record(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You are not allowed to manage AutoPage.', 'autopage' ) );
        }

        $id = isset( $_GET['record_id'] ) ? absint( $_GET['record_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        check_admin_referer( 'autopage_duplicate_record_' . $id );

        $row = self::get_record_by_id( $id );
        if ( ! $row ) {
            wp_safe_redirect( self::admin_url_with( array( 'autopage_notice' => 'invalid' ) ) );
            exit;
        }

        global $wpdb;
        $table = self::table_name();
        $base_content_id = sanitize_key( (string) $row['content_id'] . '_copy' );
        $new_content_id = $base_content_id;
        $counter = 2;

        while ( (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE record_key = %s", self::record_key( (int) $row['base_page_id'], (string) $row['slug'], $new_content_id ) ) ) > 0 ) {
            $new_content_id = sanitize_key( $base_content_id . '_' . $counter );
            ++$counter;
        }

        $now = current_time( 'mysql' );
        $wpdb->insert(
            $table,
            array(
                'title'        => 'Copy of ' . (string) $row['title'],
                'base_page_id' => (int) $row['base_page_id'],
                'slug'         => (string) $row['slug'],
                'content_id'   => $new_content_id,
                'type'         => (string) $row['type'],
                'data'         => wp_json_encode( $row['data'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ?: '{}',
                'status'       => 'inactive',
                'author_id'    => get_current_user_id(),
                'record_key'   => self::record_key( (int) $row['base_page_id'], (string) $row['slug'], $new_content_id ),
                'created_at'   => $now,
                'updated_at'   => $now,
            ),
            array( '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
        );

        wp_safe_redirect( self::admin_url_with( array( 'autopage_notice' => 'duplicated' ) ) );
        exit;
    }

    public static function export_csv(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You are not allowed to manage AutoPage.', 'autopage' ) );
        }

        check_admin_referer( 'autopage_export_csv' );

        global $wpdb;
        $table = self::table_name();
        $rows = $wpdb->get_results(
            "SELECT c.*, u.user_login AS author_login FROM {$table} c LEFT JOIN {$wpdb->users} u ON u.ID = c.author_id ORDER BY c.id ASC",
            ARRAY_A
        ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        nocache_headers();
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="autopage-' . gmdate( 'Y-m-d-His' ) . '.csv"' );

        $out = fopen( 'php://output', 'wb' );
        if ( false === $out ) {
            exit;
        }

        $headers = array( 'title', 'base_page_id', 'url_slug', 'content_id', 'type', 'status', 'author_login', 'created_at', 'rich_text', 'attachment_id', 'external_video_url', 'embed_url', 'embed_title', 'button_text', 'button_url', 'button_new_tab' );
        fputcsv( $out, $headers, ',', '"', '' );

        if ( is_array( $rows ) ) {
            foreach ( $rows as $row ) {
                $data = json_decode( (string) $row['data'], true );
                if ( ! is_array( $data ) ) {
                    $data = array();
                }

                fputcsv(
                    $out,
                    array(
                        (string) $row['title'],
                        (string) $row['base_page_id'],
                        (string) $row['slug'],
                        (string) $row['content_id'],
                        (string) $row['type'],
                        (string) $row['status'],
                        (string) ( $row['author_login'] ?? '' ),
                        (string) $row['created_at'],
                        'rich_text' === $row['type'] ? (string) ( $data['html'] ?? '' ) : '',
                        'image' === $row['type'] ? (string) ( $data['attachment_id'] ?? '' ) : '',
                        'external_video' === $row['type'] ? (string) ( $data['url'] ?? '' ) : '',
                        'embed_url' === $row['type'] ? (string) ( $data['url'] ?? '' ) : '',
                        'embed_url' === $row['type'] ? (string) ( $data['title'] ?? '' ) : '',
                        'button' === $row['type'] ? (string) ( $data['label'] ?? '' ) : '',
                        'button' === $row['type'] ? (string) ( $data['url'] ?? '' ) : '',
                        'button' === $row['type'] && ! empty( $data['new_tab'] ) ? '1' : '0',
                    ),
                    ',',
                    '"',
                    ''
                );
            }
        }

        fclose( $out );
        exit;
    }

    public static function download_sample_csv(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You are not allowed to manage AutoPage.', 'autopage' ) );
        }

        check_admin_referer( 'autopage_download_sample_csv' );
        nocache_headers();
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="autopage-sample.csv"' );

        $out = fopen( 'php://output', 'wb' );
        if ( false === $out ) {
            exit;
        }

        fputcsv( $out, array( 'title', 'base_page_id', 'url_slug', 'content_id', 'type', 'status', 'rich_text', 'attachment_id', 'external_video_url', 'embed_url', 'embed_title', 'button_text', 'button_url', 'button_new_tab' ), ',', '"', '' );
        fclose( $out );
        exit;
    }

    public static function import_csv(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You are not allowed to manage AutoPage.', 'autopage' ) );
        }

        check_admin_referer( 'autopage_import_csv' );

        if ( empty( $_FILES['autopage_csv']['tmp_name'] ) || UPLOAD_ERR_OK !== (int) ( $_FILES['autopage_csv']['error'] ?? UPLOAD_ERR_NO_FILE ) ) {
            wp_safe_redirect( self::admin_url_with( array( 'autopage_notice' => 'import_error' ) ) );
            exit;
        }

        $handle = fopen( (string) $_FILES['autopage_csv']['tmp_name'], 'rb' );
        if ( false === $handle ) {
            wp_safe_redirect( self::admin_url_with( array( 'autopage_notice' => 'import_error' ) ) );
            exit;
        }

        $header = fgetcsv( $handle, 0, ',', '"', '' );
        $required = array( 'title', 'base_page_id', 'url_slug', 'content_id', 'type', 'status' );

        if ( ! is_array( $header ) || array_diff( $required, $header ) ) {
            fclose( $handle );
            wp_safe_redirect( self::admin_url_with( array( 'autopage_notice' => 'import_error' ) ) );
            exit;
        }

        $indexes = array_flip( $header );
        $imported = 0;
        global $wpdb;
        $table = self::table_name();

        while ( ( $csv = fgetcsv( $handle, 0, ',', '"', '' ) ) !== false ) {
            if ( ! is_array( $csv ) ) {
                continue;
            }

            $get = static function ( string $key ) use ( $indexes, $csv ): string {
                return isset( $indexes[ $key ], $csv[ $indexes[ $key ] ] ) ? (string) $csv[ $indexes[ $key ] ] : '';
            };

            $title = sanitize_text_field( $get( 'title' ) );
            $base_page_id = absint( $get( 'base_page_id' ) );
            $slug = sanitize_title( $get( 'url_slug' ) );
            $content_id = sanitize_key( $get( 'content_id' ) );
            $type = sanitize_key( $get( 'type' ) );
            $status = sanitize_key( $get( 'status' ) );

            if (
                '' === $title ||
                $base_page_id <= 0 ||
                '' === $content_id ||
                ! in_array( $type, self::CONTENT_TYPES, true ) ||
                ! in_array( $status, self::STATUSES, true ) ||
                'page' !== get_post_type( $base_page_id ) ||
                'publish' !== get_post_status( $base_page_id )
            ) {
                continue;
            }

            $source = array(
                'rich_text'         => $get( 'rich_text' ),
                'attachment_id'     => $get( 'attachment_id' ),
                'external_video_url'=> $get( 'external_video_url' ),
                'embed_url'         => $get( 'embed_url' ),
                'embed_title'       => $get( 'embed_title' ),
                'button_label'      => $get( 'button_text' ),
                'button_url'        => $get( 'button_url' ),
                'button_new_tab'    => $get( 'button_new_tab' ),
            );
            $data = self::sanitize_content_data( $type, $source );
            $encoded = wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
            if ( ! is_string( $encoded ) ) {
                $encoded = '{}';
            }

            $author_id = get_current_user_id();
            $now = current_time( 'mysql' );
            $record_key = self::record_key( $base_page_id, $slug, $content_id );
            $existing_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE record_key = %s LIMIT 1", $record_key ) );

            $payload = array(
                'title'        => $title,
                'base_page_id' => $base_page_id,
                'slug'         => $slug,
                'content_id'   => $content_id,
                'type'         => $type,
                'data'         => $encoded,
                'status'       => $status,
                'author_id'    => $author_id,
                'record_key'   => $record_key,
                'updated_at'   => $now,
            );
            $formats = array( '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' );

            if ( $existing_id > 0 ) {
                $wpdb->update( $table, $payload, array( 'id' => $existing_id ), $formats, array( '%d' ) );
            } else {
                $payload['created_at'] = $now;
                $formats[] = '%s';
                $wpdb->insert( $table, $payload, $formats );
            }

            ++$imported;
        }

        fclose( $handle );
        flush_rewrite_rules();
        wp_safe_redirect( self::admin_url_with( array( 'autopage_notice' => 'imported', 'imported' => $imported ) ) );
        exit;
    }

    /** @return array<string,mixed>|null */
    public static function get_record_by_id( int $id ): ?array {
        if ( $id <= 0 ) {
            return null;
        }

        global $wpdb;
        $table = self::table_name();
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", $id ), ARRAY_A );

        if ( ! is_array( $row ) ) {
            return null;
        }

        $data = json_decode( (string) $row['data'], true );
        $row['data'] = is_array( $data ) ? $data : array();
        return $row;
    }

    /** @return array<string,mixed>|null */
    private static function get_row_for_request( int $base_page_id, string $slug, string $content_id ): ?array {
        global $wpdb;
        $table = self::table_name();
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, base_page_id, slug, content_id, type, data, status FROM {$table} WHERE record_key = %s AND status = 'active' LIMIT 1",
                self::record_key( $base_page_id, $slug, $content_id )
            ),
            ARRAY_A
        );

        if ( ! is_array( $row ) ) {
            return null;
        }

        $data = json_decode( (string) $row['data'], true );
        return array(
            'id'           => (int) $row['id'],
            'base_page_id' => (int) $row['base_page_id'],
            'slug'         => (string) $row['slug'],
            'content_id'   => (string) $row['content_id'],
            'type'         => (string) $row['type'],
            'status'       => (string) $row['status'],
            'data'         => is_array( $data ) ? $data : array(),
        );
    }

    /** @return array<int,array{base_page_id:int,slug:string}> */
    private static function get_virtual_routes(): array {
        global $wpdb;
        $table = self::table_name();
        $rows = $wpdb->get_results( "SELECT DISTINCT base_page_id, slug FROM {$table} WHERE status = 'active' AND slug <> ''", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $routes = array();

        if ( is_array( $rows ) ) {
            foreach ( $rows as $row ) {
                $routes[] = array(
                    'base_page_id' => (int) $row['base_page_id'],
                    'slug'         => (string) $row['slug'],
                );
            }
        }

        return $routes;
    }

    private static function get_page_relative_path( int $page_id ): string {
        $permalink = get_permalink( $page_id );
        if ( ! is_string( $permalink ) || '' === $permalink ) {
            return '';
        }

        $page_path = (string) wp_parse_url( $permalink, PHP_URL_PATH );
        $home_path = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
        $page_path = trim( $page_path, '/' );
        $home_path = trim( $home_path, '/' );

        if ( '' !== $home_path && str_starts_with( $page_path, $home_path . '/' ) ) {
            $page_path = substr( $page_path, strlen( $home_path ) + 1 );
        } elseif ( $page_path === $home_path ) {
            $page_path = '';
        }

        return trim( $page_path, '/' );
    }

    /** @param array<string,int|string> $args */
    public static function admin_url_with( array $args = array() ): string {
        return add_query_arg( array_merge( array( 'page' => self::MENU_SLUG ), $args ), admin_url( 'admin.php' ) );
    }

    public static function per_page(): int {
        return self::PER_PAGE;
    }

    public static function activate(): void {
        self::create_database_table();
        self::register_rewrite_rules();
        flush_rewrite_rules();
    }

    public static function deactivate(): void {
        flush_rewrite_rules();
    }
}

AutoPage_Plugin::init();
register_activation_hook( __FILE__, array( 'AutoPage_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'AutoPage_Plugin', 'deactivate' ) );

if ( is_admin() ) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';

    final class AutoPage_List_Table extends WP_List_Table {
        /** @var array<string,array<string,mixed>> */
        private array $editor_records = array();

        public function __construct() {
            parent::__construct(
                array(
                    'singular' => 'autopage_content',
                    'plural'   => 'autopage_contents',
                    'ajax'     => false,
                )
            );
        }

        /** @return array<string,string> */
        public function get_columns(): array {
            return array(
                'cb'         => '<input type="checkbox" />',
                'title'      => 'Title',
                'base_page'  => 'Base Page',
                'slug'       => 'URL Slug',
                'content_id' => 'Content ID',
                'type'       => 'Type',
                'status'     => 'Status',
                'author'     => 'Author',
                'date'       => 'Date and Time',
                'actions'    => 'Actions',
            );
        }

        /** @return array<string,array{0:string,1:bool}> */
        protected function get_sortable_columns(): array {
            return array(
                'title'      => array( 'title', false ),
                'base_page'  => array( 'base_page', false ),
                'slug'       => array( 'slug', false ),
                'content_id' => array( 'content_id', false ),
                'type'       => array( 'type', false ),
                'status'     => array( 'status', false ),
                'author'     => array( 'author', false ),
                'date'       => array( 'date', true ),
            );
        }

        /** @return array<string,string> */
        protected function get_views(): array {
            global $wpdb;
            $table = $wpdb->prefix . 'autopage_content';
            $counts = array( 'all' => 0, 'active' => 0, 'inactive' => 0 );
            $rows = $wpdb->get_results( "SELECT status, COUNT(*) AS total FROM {$table} GROUP BY status", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

            if ( is_array( $rows ) ) {
                foreach ( $rows as $row ) {
                    $count = (int) $row['total'];
                    $counts['all'] += $count;
                    if ( isset( $counts[ $row['status'] ] ) ) {
                        $counts[ $row['status'] ] = $count;
                    }
                }
            }

            $current = isset( $_GET['status'] ) ? sanitize_key( (string) wp_unslash( $_GET['status'] ) ) : 'all'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $base = AutoPage_Plugin::admin_url_with();

            return array(
                'all' => sprintf( '<a href="%s"%s>All <span class="count">(%d)</span></a>', esc_url( $base ), 'all' === $current ? ' class="current" aria-current="page"' : '', $counts['all'] ),
                'active' => sprintf( '<a href="%s"%s>Active <span class="count">(%d)</span></a>', esc_url( add_query_arg( 'status', 'active', $base ) ), 'active' === $current ? ' class="current" aria-current="page"' : '', $counts['active'] ),
                'inactive' => sprintf( '<a href="%s"%s>Inactive <span class="count">(%d)</span></a>', esc_url( add_query_arg( 'status', 'inactive', $base ) ), 'inactive' === $current ? ' class="current" aria-current="page"' : '', $counts['inactive'] ),
            );
        }

        /** @return array<string,string> */
        protected function get_bulk_actions(): array {
            return array(
                'set_active'   => 'Set Active',
                'set_inactive' => 'Set Inactive',
                'remove'       => 'Remove',
            );
        }

        private function process_bulk_action(): void {
            $action = $this->current_action();
            if ( ! in_array( $action, array( 'set_active', 'set_inactive', 'remove' ), true ) ) {
                return;
            }
            if ( ! current_user_can( 'manage_options' ) ) {
                return;
            }
            if ( ! isset( $_POST['autopage_bulk_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['autopage_bulk_nonce'] ) ), 'autopage_bulk_action' ) ) {
                return;
            }

            $ids = isset( $_POST['content_ids'] ) && is_array( $_POST['content_ids'] ) ? array_values( array_filter( array_map( 'absint', wp_unslash( $_POST['content_ids'] ) ) ) ) : array();
            if ( empty( $ids ) ) {
                return;
            }

            global $wpdb;
            $table = $wpdb->prefix . 'autopage_content';
            $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
            if ( 'remove' === $action ) {
                $sql = $wpdb->prepare( "DELETE FROM {$table} WHERE id IN ({$placeholders})", ...$ids );
                $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            } else {
                $status = 'set_active' === $action ? 'active' : 'inactive';
                $now = current_time( 'mysql' );
                $params = array_merge( array( $status, $now ), $ids );
                $sql = $wpdb->prepare( "UPDATE {$table} SET status=%s, updated_at=%s WHERE id IN ({$placeholders})", ...$params );
                $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            }
            flush_rewrite_rules();
        }

        public function prepare_items(): void {
            $this->process_bulk_action();
            global $wpdb;
            $table = $wpdb->prefix . 'autopage_content';
            $posts = $wpdb->posts;
            $users = $wpdb->users;

            $search = isset( $_REQUEST['s'] ) ? sanitize_text_field( (string) wp_unslash( $_REQUEST['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $status = isset( $_REQUEST['status'] ) ? sanitize_key( (string) wp_unslash( $_REQUEST['status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            if ( ! in_array( $status, array( 'active', 'inactive' ), true ) ) {
                $status = '';
            }

            $orderby_requested = isset( $_REQUEST['orderby'] ) ? sanitize_key( (string) wp_unslash( $_REQUEST['orderby'] ) ) : 'date'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $order_requested = isset( $_REQUEST['order'] ) ? strtolower( sanitize_key( (string) wp_unslash( $_REQUEST['order'] ) ) ) : 'desc'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $order = 'asc' === $order_requested ? 'ASC' : 'DESC';

            $order_map = array(
                'title'      => 'c.title',
                'base_page'  => 'p.post_title',
                'slug'       => 'c.slug',
                'content_id' => 'c.content_id',
                'type'       => 'c.type',
                'status'     => 'c.status',
                'author'     => 'u.display_name',
                'date'       => 'c.created_at',
            );
            $orderby = $order_map[ $orderby_requested ] ?? 'c.created_at';

            $where = array( '1=1' );
            $params = array();

            if ( '' !== $status ) {
                $where[] = 'c.status = %s';
                $params[] = $status;
            }

            if ( '' !== $search ) {
                $like = '%' . $wpdb->esc_like( $search ) . '%';
                $where[] = '(c.title LIKE %s OR p.post_title LIKE %s OR c.slug LIKE %s OR c.content_id LIKE %s)';
                array_push( $params, $like, $like, $like, $like );
            }

            $where_sql = implode( ' AND ', $where );
            $count_sql = "SELECT COUNT(*) FROM {$table} c LEFT JOIN {$posts} p ON p.ID = c.base_page_id LEFT JOIN {$users} u ON u.ID = c.author_id WHERE {$where_sql}";
            $total_items = empty( $params )
                ? (int) $wpdb->get_var( $count_sql ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                : (int) $wpdb->get_var( $wpdb->prepare( $count_sql, ...$params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

            $per_page = AutoPage_Plugin::per_page();
            $current_page = max( 1, $this->get_pagenum() );
            $offset = ( $current_page - 1 ) * $per_page;

            $data_sql = "SELECT c.*, p.post_title AS base_page_title, u.display_name AS author_display
                FROM {$table} c
                LEFT JOIN {$posts} p ON p.ID = c.base_page_id
                LEFT JOIN {$users} u ON u.ID = c.author_id
                WHERE {$where_sql}
                ORDER BY {$orderby} {$order}, c.id DESC
                LIMIT %d OFFSET %d";
            $data_params = array_merge( $params, array( $per_page, $offset ) );
            $rows = $wpdb->get_results( $wpdb->prepare( $data_sql, ...$data_params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

            $this->items = is_array( $rows ) ? $rows : array();
            $this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'title' );
            $this->set_pagination_args(
                array(
                    'total_items' => $total_items,
                    'per_page'    => $per_page,
                    'total_pages' => (int) ceil( $total_items / $per_page ),
                )
            );

            foreach ( $this->items as $row ) {
                $data = json_decode( (string) $row['data'], true );
                if ( ! is_array( $data ) ) {
                    $data = array();
                }

                $attachment_id = isset( $data['attachment_id'] ) ? absint( $data['attachment_id'] ) : 0;
                $this->editor_records[ (string) $row['id'] ] = array(
                    'id'              => (int) $row['id'],
                    'title'           => (string) $row['title'],
                    'base_page_id'    => (int) $row['base_page_id'],
                    'slug'            => (string) $row['slug'],
                    'content_id'      => (string) $row['content_id'],
                    'type'            => (string) $row['type'],
                    'status'          => (string) $row['status'],
                    'data'            => $data,
                    'author_display'  => (string) ( $row['author_display'] ?: '—' ),
                    'created_display' => mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (string) $row['created_at'] ),
                    'image_preview'   => $attachment_id ? (string) wp_get_attachment_image_url( $attachment_id, 'medium' ) : '',
                );
            }
        }

        /** @return array<string,array<string,mixed>> */
        public function editor_records(): array {
            return $this->editor_records;
        }

        public function no_items(): void {
            echo 'No AutoPage content found.';
        }

        /** @param array<string,mixed> $item */
        public function column_cb( $item ): string {
            return '<input type="checkbox" name="content_ids[]" value="' . esc_attr( (string) (int) $item['id'] ) . '" />';
        }

        /** @param array<string,mixed> $item */
        public function column_title( $item ): string {
            $id = (int) $item['id'];
            return '<strong><a href="#" class="row-title autopage-edit-record" data-id="' . esc_attr( (string) $id ) . '">' . esc_html( (string) $item['title'] ) . '</a></strong>';
        }

        /** @param array<string,mixed> $item */
        public function column_actions( $item ): string {
            $id = (int) $item['id'];
            $duplicate_url = wp_nonce_url(
                admin_url( 'admin-post.php?action=autopage_duplicate_record&record_id=' . $id ),
                'autopage_duplicate_record_' . $id
            );
            $remove_url = wp_nonce_url(
                admin_url( 'admin-post.php?action=autopage_delete_record&record_id=' . $id ),
                'autopage_delete_record_' . $id
            );

            return '<span class="autopage-row-actions"><a href="#" class="autopage-edit-record" data-id="' . esc_attr( (string) $id ) . '">Edit</a> <span aria-hidden="true">|</span> <a href="' . esc_url( $duplicate_url ) . '">Duplicate</a> <span aria-hidden="true">|</span> <span class="delete"><a href="' . esc_url( $remove_url ) . '" onclick="return confirm(\'Remove this content?\');">Remove</a></span></span>';
        }

        /** @param array<string,mixed> $item */
        public function column_base_page( $item ): string {
            return esc_html( (string) ( $item['base_page_title'] ?: 'Page #' . (int) $item['base_page_id'] ) );
        }

        /** @param array<string,mixed> $item */
        public function column_slug( $item ): string {
            return '' === (string) $item['slug'] ? '<em>Base page</em>' : '<code>' . esc_html( (string) $item['slug'] ) . '</code>';
        }

        /** @param array<string,mixed> $item */
        public function column_content_id( $item ): string {
            return '<code>' . esc_html( (string) $item['content_id'] ) . '</code>';
        }

        /** @param array<string,mixed> $item */
        public function column_type( $item ): string {
            $labels = AutoPage_Plugin::content_type_labels();
            return esc_html( $labels[ (string) $item['type'] ] ?? (string) $item['type'] );
        }

        /** @param array<string,mixed> $item */
        public function column_status( $item ): string {
            $status = (string) $item['status'];
            $labels = AutoPage_Plugin::status_labels();
            return '<span class="autopage-status-' . esc_attr( $status ) . '">' . esc_html( $labels[ $status ] ?? $status ) . '</span>';
        }

        /** @param array<string,mixed> $item */
        public function column_author( $item ): string {
            return esc_html( (string) ( $item['author_display'] ?: '—' ) );
        }

        /** @param array<string,mixed> $item */
        public function column_date( $item ): string {
            return esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (string) $item['created_at'] ) );
        }

        /** @param array<string,mixed> $item */
        public function column_default( $item, $column_name ): string {
            return isset( $item[ $column_name ] ) ? esc_html( (string) $item[ $column_name ] ) : '';
        }
    }
}
