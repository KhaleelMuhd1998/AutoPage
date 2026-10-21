<?php
/**
 * Plugin Name: AutoPage
 * Plugin URI: https://github.com/KhaleelMuhd1998/AutoPage
 * Description: Creates virtual child URLs for selected WordPress pages and renders typed dynamic content through [autopage] shortcodes.
 * Version: 0.1.0
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
    private const VERSION = '0.6.0';
    private const DB_VERSION = '2';
    private const DB_VERSION_OPTION = 'autopage_db_version';
    private const QUERY_VAR = 'autopage_slug';
    private const MENU_SLUG = 'autopage';

    /** @var string[] */
    private const CONTENT_TYPES = array( 'rich_text', 'image', 'video_embed', 'iframe', 'button' );

    public static function init(): void {
        add_action( 'plugins_loaded', array( __CLASS__, 'maybe_upgrade_database' ) );
        add_action( 'init', array( __CLASS__, 'register_shortcodes' ) );
        add_action( 'init', array( __CLASS__, 'register_rewrite_rules' ), 20 );
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_frontend_assets' ) );

        add_filter( 'query_vars', array( __CLASS__, 'register_query_vars' ) );
        add_filter( 'redirect_canonical', array( __CLASS__, 'prevent_virtual_url_canonical_redirect' ), 10, 2 );
        add_action( 'template_redirect', array( __CLASS__, 'validate_virtual_request' ), 1 );

        if ( is_admin() ) {
            add_action( 'admin_menu', array( __CLASS__, 'register_admin_page' ) );
            add_action( 'admin_post_autopage_save', array( __CLASS__, 'save_admin_settings' ) );
            add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_assets' ) );
        }
    }

    private static function table_name(): string {
        global $wpdb;
        return $wpdb->prefix . 'autopage_content';
    }

    public static function maybe_upgrade_database(): void {
        if ( get_option( self::DB_VERSION_OPTION ) !== self::DB_VERSION ) {
            self::create_database_table();
            update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
        }
    }

    private static function create_database_table(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = self::table_name();
        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            title varchar(255) NOT NULL DEFAULT '',
            base_page_id bigint(20) unsigned NOT NULL,
            slug varchar(200) NOT NULL,
            content_id varchar(191) NOT NULL,
            type varchar(32) NOT NULL,
            data longtext NOT NULL,
            record_key char(64) NOT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY record_key (record_key),
            KEY route_lookup (base_page_id, slug(100)),
            KEY content_lookup (base_page_id, slug(100), content_id(100))
        ) {$charset_collate};";
        dbDelta( $sql );
    }

    private static function record_key( int $base_page_id, string $slug, string $content_id ): string {
        return hash( 'sha256', $base_page_id . '|' . $slug . '|' . $content_id );
    }

    /** @param array<string,mixed> $data */
    private static function upsert_row( int $id, string $title, int $base_page_id, string $slug, string $content_id, string $type, array $data ): int {
        global $wpdb;
        if ( $base_page_id <= 0 || '' === $content_id ) return 0;
        $table = self::table_name();
        $now = current_time( 'mysql' );
        $encoded = wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        if ( ! is_string( $encoded ) ) $encoded = '{}';
        $payload = array(
            'title' => $title,
            'base_page_id' => $base_page_id,
            'slug' => $slug,
            'content_id' => $content_id,
            'type' => $type,
            'data' => $encoded,
            'record_key' => self::record_key( $base_page_id, $slug, $content_id ),
            'updated_at' => $now,
        );
        $formats = array( '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s' );
        if ( $id > 0 ) {
            $updated = $wpdb->update( $table, $payload, array( 'id' => $id ), $formats, array( '%d' ) );
            if ( false !== $updated ) return $id;
        }
        $existing_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE record_key = %s", $payload['record_key'] ) );
        if ( $existing_id > 0 ) {
            $wpdb->update( $table, $payload, array( 'id' => $existing_id ), $formats, array( '%d' ) );
            return $existing_id;
        }
        $payload['created_at'] = $now;
        $formats[] = '%s';
        $wpdb->insert( $table, $payload, $formats );
        return (int) $wpdb->insert_id;
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
            if ( '' === $base_path || '' === $slug ) continue;
            $virtual_path = trim( $base_path . '/' . $slug, '/' );
            $existing_page = get_page_by_path( $virtual_path, OBJECT, 'page' );
            if ( $existing_page instanceof WP_Post && 'publish' === $existing_page->post_status ) continue;
            $regex = '^' . preg_quote( $virtual_path, '#' ) . '/?$';
            $query = 'index.php?page_id=' . $base_page_id . '&' . self::QUERY_VAR . '=' . rawurlencode( $slug );
            add_rewrite_rule( $regex, $query, 'top' );
        }
    }

    public static function enqueue_frontend_assets(): void {
        $css = '.autopage-image-wrap{max-width:100%;}.autopage-image-wrap img.autopage-image{display:block;max-width:100%;height:auto;}.autopage-embed{position:relative;width:100%;max-width:100%;overflow:hidden;}.autopage-video-embed iframe,.autopage-iframe iframe{display:block;max-width:100%;width:100%;border:0;}.autopage-video-embed{aspect-ratio:16/9;}.autopage-video-embed iframe{width:100%;height:100%;}.autopage-iframe iframe{min-height:450px;}';
        wp_register_style( 'autopage-inline', false, array(), self::VERSION );
        wp_enqueue_style( 'autopage-inline' );
        wp_add_inline_style( 'autopage-inline', $css );
    }

    /** @param array<string,mixed> $atts */
    public static function render_shortcode( array $atts ): string {
        $atts = shortcode_atts( array( 'contentid' => '' ), $atts, 'autopage' );
        $content_id = sanitize_key( (string) $atts['contentid'] );
        $slug = sanitize_title( (string) get_query_var( self::QUERY_VAR ) );
        if ( '' === $content_id ) return '';
        $row = self::get_row_for_request( get_queried_object_id(), $slug, $content_id );
        return $row ? self::render_content( $row ) : '';
    }

    /** @param array<string,mixed> $row */
    private static function render_content( array $row ): string {
        $data = is_array( $row['data'] ) ? $row['data'] : array();
        return match ( (string) $row['type'] ) {
            'rich_text' => self::render_rich_text( $data ),
            'image' => self::render_image( $data ),
            'video_embed' => self::render_video_embed( $data ),
            'iframe' => self::render_iframe( $data ),
            'button' => self::render_button( $data ),
            default => '',
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
        if ( ! $attachment_id || 'attachment' !== get_post_type( $attachment_id ) ) return '';
        $image = wp_get_attachment_image( $attachment_id, 'full', false, array( 'class' => 'autopage-image', 'loading' => 'lazy' ) );
        return $image ? '<div class="autopage-image-wrap">' . $image . '</div>' : '';
    }

    /** @param array<string,mixed> $data */
    private static function render_video_embed( array $data ): string {
        $url = isset( $data['url'] ) ? esc_url( (string) $data['url'] ) : '';
        if ( '' === $url || ! self::is_supported_video_url( $url ) ) return '';
        $oembed = wp_oembed_get( $url );
        if ( ! is_string( $oembed ) || '' === $oembed ) return '';
        $allowed = array( 'iframe' => array( 'src'=>true,'width'=>true,'height'=>true,'frameborder'=>true,'allow'=>true,'allowfullscreen'=>true,'loading'=>true,'referrerpolicy'=>true,'title'=>true,'class'=>true ) );
        return '<div class="autopage-embed autopage-video-embed">' . wp_kses( $oembed, $allowed ) . '</div>';
    }

    private static function is_supported_video_url( string $url ): bool {
        $host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
        $host = preg_replace( '/^www\./', '', $host );
        return in_array( $host, array( 'youtube.com', 'youtu.be', 'm.youtube.com', 'vimeo.com', 'player.vimeo.com' ), true );
    }

    /** @param array<string,mixed> $data */
    private static function render_iframe( array $data ): string {
        $url = isset( $data['url'] ) ? esc_url( (string) $data['url'] ) : '';
        $title = isset( $data['title'] ) ? sanitize_text_field( (string) $data['title'] ) : '';
        if ( '' === $url ) return '';
        return sprintf(
            '<div class="autopage-embed autopage-iframe"><iframe src="%1$s" title="%2$s" loading="lazy" referrerpolicy="strict-origin-when-cross-origin" allowfullscreen></iframe></div>',
            esc_url( $url ), esc_attr( $title )
        );
    }

    /** @param array<string,mixed> $data */
    private static function render_button( array $data ): string {
        $label = isset( $data['label'] ) ? sanitize_text_field( (string) $data['label'] ) : '';
        $url = isset( $data['url'] ) ? esc_url( (string) $data['url'] ) : '';
        $new_tab = ! empty( $data['new_tab'] );
        if ( '' === $label || '' === $url ) return '';
        $target = $new_tab ? ' target="_blank" rel="noopener noreferrer"' : '';
        return sprintf( '<a class="autopage-button wp-element-button" href="%1$s"%2$s>%3$s</a>', esc_url( $url ), $target, esc_html( $label ) );
    }

    public static function prevent_virtual_url_canonical_redirect( string|false $redirect_url, string $requested_url ): string|false {
        unset( $requested_url );
        return get_query_var( self::QUERY_VAR ) ? false : $redirect_url;
    }

    public static function validate_virtual_request(): void {
        $slug = sanitize_title( (string) get_query_var( self::QUERY_VAR ) );
        if ( '' === $slug ) return;
        if ( self::virtual_slug_exists( get_queried_object_id(), $slug ) ) return;
        global $wp_query;
        if ( $wp_query instanceof WP_Query ) $wp_query->set_404();
        status_header( 404 );
        nocache_headers();
    }

    private static function virtual_slug_exists( int $base_page_id, string $slug ): bool {
        global $wpdb;
        $table = self::table_name();
        return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$table} WHERE base_page_id=%d AND slug=%s LIMIT 1", $base_page_id, $slug ) );
    }

    public static function register_admin_page(): void {
        add_menu_page( 'AutoPage', 'AutoPage', 'manage_options', self::MENU_SLUG, array( __CLASS__, 'render_admin_page' ), 'dashicons-admin-page', 58 );
    }

    public static function enqueue_admin_assets( string $hook_suffix ): void {
        if ( 'toplevel_page_' . self::MENU_SLUG !== $hook_suffix ) return;
        wp_enqueue_media();
        wp_enqueue_editor();
    }

    public static function render_admin_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) return;
        $rows = self::get_rows();
        $pages = get_pages( array( 'post_status'=>'publish', 'sort_column'=>'post_title', 'sort_order'=>'ASC' ) );
        if ( empty( $rows ) ) $rows[] = self::empty_row();
        ?>
        <div class="wrap">
            <h1>AutoPage</h1>
            <?php if ( isset( $_GET['updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
                <div class="notice notice-success is-dismissible"><p>Contents saved.</p></div>
            <?php endif; ?>
            <p><strong>Shortcode example:</strong> <code>[autopage contentid="sample_content"]</code></p>
            <p><strong>Permalink requirement:</strong> Use any WordPress pretty permalink structure. The <em>Plain</em> structure (<code>?p=123</code>) is not supported for AutoPage virtual slug URLs.</p>
            <p><strong>Storage information:</strong> AutoPage stores each content as a row in <code>wp_autopage_content</code>.</p>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="autopage-form">
                <input type="hidden" name="action" value="autopage_save">
                <?php wp_nonce_field( 'autopage_save_settings', 'autopage_nonce' ); ?>
                <table class="widefat striped" id="autopage-table">
                    <thead><tr><th>Title</th><th>Base Page</th><th>URL Slug</th><th>Content ID</th><th>Type</th><th>Content</th><th style="width:110px">Actions</th></tr></thead>
                    <tbody><?php foreach ( $rows as $index => $row ) self::render_admin_row( (string) $index, $row, $pages ); ?></tbody>
                </table>
                <p><button type="button" class="button" id="autopage-add-row">Add content field</button></p>
                <?php submit_button( 'Save Contents' ); ?>
            </form>

            <div id="autopage-modal" class="autopage-modal" hidden>
                <div class="autopage-modal-backdrop" data-autopage-close></div>
                <div class="autopage-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="autopage-modal-title">
                    <div class="autopage-modal-header"><h2 id="autopage-modal-title">Edit content</h2><button type="button" class="button-link autopage-modal-x" data-autopage-close aria-label="Close">×</button></div>
                    <div class="autopage-modal-body">
                        <div class="autopage-modal-field" data-editor-type="rich_text"><label for="autopage-modal-rich"><strong>Rich Text</strong></label><textarea id="autopage-modal-rich" rows="12"></textarea></div>
                        <div class="autopage-modal-field" data-editor-type="image" hidden>
                            <input type="hidden" id="autopage-modal-image-id"><div id="autopage-modal-image-preview"></div><p><button type="button" class="button" id="autopage-modal-select-image">Select image</button> <button type="button" class="button" id="autopage-modal-remove-image">Remove image</button></p>
                        </div>
                        <div class="autopage-modal-field" data-editor-type="video_embed" hidden><label><strong>YouTube / Vimeo URL</strong><input type="url" id="autopage-modal-video-url" class="regular-text" placeholder="https://www.youtube.com/watch?v=..."></label><p class="description">Only YouTube and Vimeo URLs are accepted for this type.</p></div>
                        <div class="autopage-modal-field" data-editor-type="iframe" hidden><label><strong>Iframe source URL</strong><input type="url" id="autopage-modal-iframe-url" class="regular-text" placeholder="https://www.google.com/maps/embed?pb=..."></label><p><label><strong>Accessible title</strong><input type="text" id="autopage-modal-iframe-title" class="regular-text" placeholder="Location map"></label></p><p class="description">Enter the iframe's src URL, not the complete &lt;iframe&gt; HTML.</p></div>
                        <div class="autopage-modal-field" data-editor-type="button" hidden><p><label><strong>Button text</strong><input type="text" id="autopage-modal-button-label" class="regular-text"></label></p><p><label><strong>Button URL</strong><input type="url" id="autopage-modal-button-url" class="regular-text"></label></p><label><input type="checkbox" id="autopage-modal-button-new-tab"> Open in a new tab</label></div>
                    </div>
                    <div class="autopage-modal-footer"><button type="button" class="button" data-autopage-close>Cancel</button> <button type="button" class="button button-primary" id="autopage-modal-apply">Apply</button></div>
                </div>
            </div>

        </div>
        <style>
            #autopage-table td{vertical-align:middle}.autopage-summary{max-width:360px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:#50575e}.autopage-hidden-data{display:none}.autopage-modal[hidden]{display:none}.autopage-modal{position:fixed;inset:0;z-index:100000}.autopage-modal-backdrop{position:absolute;inset:0;background:rgba(0,0,0,.55)}.autopage-modal-dialog{position:relative;background:#fff;width:min(900px,calc(100vw - 48px));max-height:calc(100vh - 48px);margin:24px auto;display:flex;flex-direction:column;box-shadow:0 12px 40px rgba(0,0,0,.3)}.autopage-modal-header,.autopage-modal-footer{padding:16px 20px;border-bottom:1px solid #dcdcde;display:flex;align-items:center;justify-content:space-between}.autopage-modal-footer{border-top:1px solid #dcdcde;border-bottom:0;justify-content:flex-end;gap:8px}.autopage-modal-body{padding:20px;overflow:auto;min-height:300px}.autopage-modal-x{font-size:30px;text-decoration:none}.autopage-modal-field input.regular-text{display:block;width:100%;max-width:none;margin-top:6px}.autopage-modal-field label{display:block}.autopage-modal-field[hidden]{display:none!important}#autopage-modal-image-preview img{display:block;max-width:100%;max-height:360px;width:auto;height:auto;margin-bottom:12px}
        </style>
        <script>
        (function(){
            const form=document.getElementById('autopage-form'), tbody=document.querySelector('#autopage-table tbody'), add=document.getElementById('autopage-add-row'), modal=document.getElementById('autopage-modal');
            if(!form||!tbody||!add||!modal)return;
            let activeRow=null, editorReady=false;
            const q=(s,r=document)=>r.querySelector(s), qa=(s,r=document)=>Array.from(r.querySelectorAll(s));
            function field(row,name){return q('[data-store="'+name+'"]',row)}
            function get(row,name){const el=field(row,name);return el?el.value:''}
            function set(row,name,val){const el=field(row,name);if(el)el.value=val??''}
            function destroyEditor(){if(editorReady&&window.wp&&wp.editor){try{wp.editor.remove('autopage-modal-rich')}catch(e){} editorReady=false}}
            function initEditor(value){const ta=q('#autopage-modal-rich');ta.value=value||'';if(window.wp&&wp.editor){wp.editor.initialize('autopage-modal-rich',{tinymce:{wpautop:true,toolbar1:'formatselect,bold,italic,bullist,numlist,blockquote,alignleft,aligncenter,alignright,link,unlink,undo,redo',toolbar2:'forecolor,pastetext,removeformat,charmap,outdent,indent'},quicktags:true,mediaButtons:true});editorReady=true}}
            function editorValue(){if(editorReady&&window.tinymce){const ed=tinymce.get('autopage-modal-rich');if(ed)return ed.getContent()}return q('#autopage-modal-rich').value}
            function showType(type){qa('.autopage-modal-field',modal).forEach(x=>x.hidden=x.dataset.editorType!==type)}
            function summary(row){const type=q('.autopage-type',row).value;let text='';if(type==='rich_text'){const div=document.createElement('div');div.innerHTML=get(row,'rich_text');text=(div.textContent||'').trim()}else if(type==='image'){text=get(row,'attachment_id')?'Media #'+get(row,'attachment_id'):'No image selected'}else if(type==='video_embed'){text=get(row,'video_url')}else if(type==='iframe'){text=get(row,'iframe_url')}else if(type==='button'){text=(get(row,'button_label')||'Button')+(get(row,'button_url')?' → '+get(row,'button_url'):'')}q('.autopage-summary',row).textContent=text||'No content yet'}
            function openModal(row){activeRow=row;destroyEditor();const type=q('.autopage-type',row).value;showType(type);if(type==='rich_text')setTimeout(()=>initEditor(get(row,'rich_text')),0);if(type==='image'){q('#autopage-modal-image-id').value=get(row,'attachment_id');const src=get(row,'image_preview');q('#autopage-modal-image-preview').innerHTML=src?'<img src="'+src.replace(/"/g,'&quot;')+'" alt="">':''}if(type==='video_embed')q('#autopage-modal-video-url').value=get(row,'video_url');if(type==='iframe'){q('#autopage-modal-iframe-url').value=get(row,'iframe_url');q('#autopage-modal-iframe-title').value=get(row,'iframe_title')}if(type==='button'){q('#autopage-modal-button-label').value=get(row,'button_label');q('#autopage-modal-button-url').value=get(row,'button_url');q('#autopage-modal-button-new-tab').checked=get(row,'button_new_tab')==='1'}modal.hidden=false;document.body.style.overflow='hidden'}
            function closeModal(){destroyEditor();modal.hidden=true;document.body.style.overflow='';activeRow=null}
            function applyModal(){if(!activeRow)return;const type=q('.autopage-type',activeRow).value;if(type==='rich_text')set(activeRow,'rich_text',editorValue());if(type==='image'){set(activeRow,'attachment_id',q('#autopage-modal-image-id').value);set(activeRow,'image_preview',q('#autopage-modal-image-preview img')?.src||'')}if(type==='video_embed')set(activeRow,'video_url',q('#autopage-modal-video-url').value);if(type==='iframe'){set(activeRow,'iframe_url',q('#autopage-modal-iframe-url').value);set(activeRow,'iframe_title',q('#autopage-modal-iframe-title').value)}if(type==='button'){set(activeRow,'button_label',q('#autopage-modal-button-label').value);set(activeRow,'button_url',q('#autopage-modal-button-url').value);set(activeRow,'button_new_tab',q('#autopage-modal-button-new-tab').checked?'1':'0')}summary(activeRow);closeModal()}
            tbody.addEventListener('click',e=>{const edit=e.target.closest('.autopage-edit-row'), remove=e.target.closest('.autopage-remove-row');if(edit){e.preventDefault();openModal(edit.closest('tr'))}if(remove){e.preventDefault()}if(remove&&confirm('Remove this content field?')){const tr=remove.closest('tr');const del=q('[data-store="delete"]',tr);if(del&&q('[data-store="id"]',tr).value){del.value='1';tr.hidden=true}else tr.remove()}});
            tbody.addEventListener('change',e=>{if(e.target.classList.contains('autopage-type'))summary(e.target.closest('tr'))});
            qa('[data-autopage-close]',modal).forEach(b=>b.addEventListener('click',closeModal));q('#autopage-modal-apply').addEventListener('click',applyModal);
            q('#autopage-modal-select-image').addEventListener('click',()=>{const frame=wp.media({title:'Select image',button:{text:'Use image'},multiple:false,library:{type:'image'}});frame.on('select',()=>{const a=frame.state().get('selection').first().toJSON();q('#autopage-modal-image-id').value=a.id||'';const src=a.sizes&&a.sizes.medium?a.sizes.medium.url:a.url;q('#autopage-modal-image-preview').innerHTML=src?'<img src="'+src+'" alt="">':''});frame.open()});
            q('#autopage-modal-remove-image').addEventListener('click',()=>{q('#autopage-modal-image-id').value='';q('#autopage-modal-image-preview').innerHTML=''});
            add.addEventListener('click',()=>{const first=tbody.querySelector('tr');if(!first)return;const row=first.cloneNode(true), idx='new_'+Date.now()+'_'+Math.floor(Math.random()*10000);row.hidden=false;row.querySelectorAll('[name]').forEach(el=>{el.name=el.name.replace(/rows\[[^\]]+\]/,'rows['+idx+']');if(el.tagName==='SELECT')el.selectedIndex=0;else el.value=''});q('[data-store="id"]',row).value='0';q('[data-store="delete"]',row).value='0';q('.autopage-summary',row).textContent='No content yet';tbody.appendChild(row)});
            document.addEventListener('keydown',e=>{if(e.key==='Escape'&&!modal.hidden)closeModal()});
            qa('tr',tbody).forEach(summary);
        }());
        </script>
        <?php
    }

    /** @param array<string,mixed> $row @param WP_Post[] $pages */
    private static function render_admin_row( string $index, array $row, array $pages ): void {
        $type = (string) $row['type']; $data = is_array( $row['data'] ) ? $row['data'] : array();
        $attachment_id = isset($data['attachment_id']) ? absint($data['attachment_id']) : 0;
        $preview = $attachment_id ? wp_get_attachment_image_url($attachment_id,'medium') : '';
        ?>
        <tr>
            <td><input type="text" name="rows[<?php echo esc_attr($index); ?>][title]" value="<?php echo esc_attr((string)($row['title']??'')); ?>" placeholder="Sample Content" required style="width:100%"></td>
            <td><select name="rows[<?php echo esc_attr($index); ?>][base_page_id]" required style="width:100%"><option value="">Select page</option><?php foreach($pages as $page): ?><option value="<?php echo esc_attr((string)$page->ID); ?>" <?php selected((int)$row['base_page_id'],$page->ID); ?>><?php echo esc_html($page->post_title.' — '.wp_make_link_relative(get_permalink($page))); ?></option><?php endforeach; ?></select></td>
            <td><input type="text" name="rows[<?php echo esc_attr($index); ?>][slug]" value="<?php echo esc_attr((string)$row['slug']); ?>" placeholder="Slug or Base Page" style="width:100%"></td>
            <td><input type="text" name="rows[<?php echo esc_attr($index); ?>][content_id]" value="<?php echo esc_attr((string)$row['content_id']); ?>" placeholder="sample_content" required style="width:100%"></td>
            <td><select class="autopage-type" name="rows[<?php echo esc_attr($index); ?>][type]" style="width:100%"><option value="rich_text" <?php selected($type,'rich_text'); ?>>Rich Text</option><option value="image" <?php selected($type,'image'); ?>>Image</option><option value="video_embed" <?php selected($type,'video_embed'); ?>>YouTube / Vimeo Video</option><option value="iframe" <?php selected($type,'iframe'); ?>>Embed (iframe URL)</option><option value="button" <?php selected($type,'button'); ?>>Button (Call-to-Action)</option></select></td>
            <td><div class="autopage-summary">No content yet</div>
                <div class="autopage-hidden-data">
                    <input type="hidden" data-store="id" name="rows[<?php echo esc_attr($index); ?>][id]" value="<?php echo esc_attr((string)($row['id']??0)); ?>">
                    <input type="hidden" data-store="delete" name="rows[<?php echo esc_attr($index); ?>][delete]" value="0">
                    <textarea data-store="rich_text" name="rows[<?php echo esc_attr($index); ?>][rich_text]"><?php echo esc_textarea((string)($data['html']??'')); ?></textarea>
                    <input data-store="attachment_id" name="rows[<?php echo esc_attr($index); ?>][attachment_id]" value="<?php echo esc_attr((string)$attachment_id); ?>">
                    <input data-store="image_preview" name="rows[<?php echo esc_attr($index); ?>][image_preview]" value="<?php echo esc_attr((string)$preview); ?>">
                    <input data-store="video_url" name="rows[<?php echo esc_attr($index); ?>][video_url]" value="<?php echo esc_attr((string)($data['url']??'')); ?>">
                    <input data-store="iframe_url" name="rows[<?php echo esc_attr($index); ?>][iframe_url]" value="<?php echo esc_attr((string)($data['url']??'')); ?>">
                    <input data-store="iframe_title" name="rows[<?php echo esc_attr($index); ?>][iframe_title]" value="<?php echo esc_attr((string)($data['title']??'')); ?>">
                    <input data-store="button_label" name="rows[<?php echo esc_attr($index); ?>][button_label]" value="<?php echo esc_attr((string)($data['label']??'')); ?>">
                    <input data-store="button_url" name="rows[<?php echo esc_attr($index); ?>][button_url]" value="<?php echo esc_attr((string)($data['url']??'')); ?>">
                    <input data-store="button_new_tab" name="rows[<?php echo esc_attr($index); ?>][button_new_tab]" value="<?php echo !empty($data['new_tab'])?'1':'0'; ?>">
                </div>
            </td>
            <td><a href="#" class="autopage-edit-row">Edit</a> <span aria-hidden="true">|</span> <a href="#" class="autopage-remove-row">Remove</a></td>
        </tr><?php
    }

    public static function save_admin_settings(): void {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( esc_html__( 'You are not allowed to manage AutoPage.', 'autopage' ) );
        check_admin_referer( 'autopage_save_settings', 'autopage_nonce' );
        $submitted = isset($_POST['rows']) && is_array($_POST['rows']) ? wp_unslash($_POST['rows']) : array();
        global $wpdb; $table=self::table_name(); $seen_ids=array();
        foreach($submitted as $row){
            if(!is_array($row))continue;
            $id=isset($row['id'])?absint($row['id']):0;
            if(!empty($row['delete'])){if($id>0)$wpdb->delete($table,array('id'=>$id),array('%d'));continue;}
            $title=isset($row['title'])?sanitize_text_field((string)$row['title']):''; $base=isset($row['base_page_id'])?absint($row['base_page_id']):0; $slug=isset($row['slug'])?sanitize_title((string)$row['slug']):''; $cid=isset($row['content_id'])?sanitize_key((string)$row['content_id']):''; $type=isset($row['type'])?sanitize_key((string)$row['type']):'rich_text';
            if(''===$title||!$base||''===$cid||!in_array($type,self::CONTENT_TYPES,true))continue;
            if('page'!==get_post_type($base)||'publish'!==get_post_status($base))continue;
            $data=match($type){
                'rich_text'=>array('html'=>isset($row['rich_text'])?wp_kses_post((string)$row['rich_text']):''),
                'image'=>array('attachment_id'=>isset($row['attachment_id'])?absint($row['attachment_id']):0),
                'video_embed'=>array('url'=>isset($row['video_url'])?esc_url_raw((string)$row['video_url']):''),
                'iframe'=>array('url'=>isset($row['iframe_url'])?esc_url_raw((string)$row['iframe_url']):'','title'=>isset($row['iframe_title'])?sanitize_text_field((string)$row['iframe_title']):''),
                'button'=>array('label'=>isset($row['button_label'])?sanitize_text_field((string)$row['button_label']):'','url'=>isset($row['button_url'])?esc_url_raw((string)$row['button_url']):'','new_tab'=>isset($row['button_new_tab'])&&'1'===(string)$row['button_new_tab']),
                default=>array(),
            };
            $saved=self::upsert_row($id,$title,$base,$slug,$cid,$type,$data); if($saved>0)$seen_ids[]=$saved;
        }
        self::register_rewrite_rules(); flush_rewrite_rules();
        wp_safe_redirect(add_query_arg(array('page'=>self::MENU_SLUG,'updated'=>'1'),admin_url('admin.php'))); exit;
    }

    /** @return array<int,array<string,mixed>> */
    private static function get_rows(): array {
        global $wpdb; $table=self::table_name();
        $db_rows=$wpdb->get_results("SELECT id,title,base_page_id,slug,content_id,type,data FROM {$table} ORDER BY title,base_page_id,slug,content_id",ARRAY_A);
        if(!is_array($db_rows))return array(); $out=array();
        foreach($db_rows as $r){$data=json_decode((string)$r['data'],true);$out[]=array('id'=>(int)$r['id'],'title'=>(string)$r['title'],'base_page_id'=>(int)$r['base_page_id'],'slug'=>(string)$r['slug'],'content_id'=>(string)$r['content_id'],'type'=>(string)$r['type'],'data'=>is_array($data)?$data:array());}
        return $out;
    }

    /** @return array<string,mixed>|null */
    private static function get_row_for_request( int $base_page_id, string $slug, string $content_id ): ?array {
        global $wpdb; $table=self::table_name();
        $r=$wpdb->get_row($wpdb->prepare("SELECT id,base_page_id,slug,content_id,type,data FROM {$table} WHERE record_key=%s LIMIT 1",self::record_key($base_page_id,$slug,$content_id)),ARRAY_A);
        if(!is_array($r))return null; $data=json_decode((string)$r['data'],true);
        return array('id'=>(int)$r['id'],'base_page_id'=>(int)$r['base_page_id'],'slug'=>(string)$r['slug'],'content_id'=>(string)$r['content_id'],'type'=>(string)$r['type'],'data'=>is_array($data)?$data:array());
    }

    /** @return array<int,array{base_page_id:int,slug:string}> */
    private static function get_virtual_routes(): array {
        global $wpdb; $table=self::table_name();
        $rows=$wpdb->get_results("SELECT DISTINCT base_page_id,slug FROM {$table}",ARRAY_A); $routes=array();
        if(is_array($rows))foreach($rows as $r)$routes[]=array('base_page_id'=>(int)$r['base_page_id'],'slug'=>(string)$r['slug']);
        return $routes;
    }

    private static function get_page_relative_path( int $page_id ): string {
        $permalink=get_permalink($page_id); if(!is_string($permalink)||''===$permalink)return '';
        $page_path=(string)wp_parse_url($permalink,PHP_URL_PATH); $home_path=(string)wp_parse_url(home_url('/'),PHP_URL_PATH); $page_path=trim($page_path,'/'); $home_path=trim($home_path,'/');
        if(''!==$home_path&&str_starts_with($page_path,$home_path.'/'))$page_path=substr($page_path,strlen($home_path)+1); elseif($page_path===$home_path)$page_path=''; return trim($page_path,'/');
    }

    /** @return array<string,mixed> */
    private static function empty_row(): array {return array('id'=>0,'title'=>'','base_page_id'=>0,'slug'=>'','content_id'=>'','type'=>'rich_text','data'=>array('html'=>''));}

    public static function activate(): void {self::create_database_table();update_option(self::DB_VERSION_OPTION,self::DB_VERSION,false);self::register_rewrite_rules();flush_rewrite_rules();}
    public static function deactivate(): void {flush_rewrite_rules();}
}

AutoPage_Plugin::init();
register_activation_hook( __FILE__, array( 'AutoPage_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'AutoPage_Plugin', 'deactivate' ) );
