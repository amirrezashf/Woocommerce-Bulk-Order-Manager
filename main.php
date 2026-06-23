<?php
/**
 * Plugin Name: Woocommerce Bulk Order Manager
 * Plugin URI: https://github.com/amirrezashf/woocommerce-bulk-order-manager
 * Description: Bulk manage WooCommerce orders, update statuses, add internal notes, preview changes, and track operations from a dedicated administration panel.
 * Version: 1.0.0
 * Author: Amirreza Shayesteh Far
 * Author URI: https://amirrezaa.ir/
 * License: GPL v2 or later
 * Text Domain: woocommerce-order-notes-column
 * Requires Plugins: woocommerce
 */

if (!defined('ABSPATH')) exit;

class FA_Bulk_Order_Editor {

	const CAP       = 'manage_woocommerce';
	const MENU_SLUG = 'fa-bulk-orders';
	const NONCE     = 'fa_bulk_orders_nonce';

	const LOG_CPT   = 'fa_bulk_orders_log';

	const MIN_ORDER_ID = 10000; // حداقل ۵ رقمی
	const MIN_COUNT    = 2;     // حداقل تعداد سفارش
	const MAX_COUNT    = 100;   // حداکثر تعداد سفارش

	const LOCK_KEY      = 'fa_bulk_orders_lock';
	const LOCK_TTL_SECS = 300; // 5 دقیقه

	public static function init() {
		add_action('init', [__CLASS__, 'register_log_cpt']);
		add_action('admin_menu', [__CLASS__, 'register_menu'], 90);
		add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_css']);

		add_action('wp_ajax_fa_bulk_orders_do_one', [__CLASS__, 'ajax_do_one']);
		add_action('wp_ajax_fa_bulk_orders_lock_acquire', [__CLASS__, 'ajax_lock_acquire']);
		add_action('wp_ajax_fa_bulk_orders_lock_release', [__CLASS__, 'ajax_lock_release']);
		add_action('wp_ajax_fa_bulk_orders_lock_status',  [__CLASS__, 'ajax_lock_status']);
		add_action('wp_ajax_fa_bulk_orders_preview', [__CLASS__, 'ajax_preview']);

		add_action('wp_ajax_fa_bulk_orders_purge_logs', [__CLASS__, 'ajax_purge_logs']); // admin-only
	}

	/* -------------------- CPT (Log) -------------------- */
	public static function register_log_cpt() {
		register_post_type(self::LOG_CPT, [
			'labels' => [
				'name' => 'لاگ ویرایش گروهی سفارشات',
				'singular_name' => 'لاگ',
			],
			'public'   => false,
			'show_ui'  => false,
			'supports' => ['title', 'editor'],
		]);
	}

	/* -------------------- Menu -------------------- */
	public static function register_menu() {
		add_menu_page(
			'ویرایش گروهی سفارشات',
			'ویرایش گروهی سفارشات',
			self::CAP,
			self::MENU_SLUG,
			[__CLASS__, 'render_status_page'],
			'dashicons-update',
			56
		);

		add_submenu_page(
			self::MENU_SLUG,
			'تغییر وضعیت سفارشات',
			'تغییر وضعیت سفارشات',
			self::CAP,
			self::MENU_SLUG,
			[__CLASS__, 'render_status_page']
		);

		add_submenu_page(
			self::MENU_SLUG,
			'افزودن یادداشت به سفارشات',
			'افزودن یادداشت به سفارشات',
			self::CAP,
			'fa-bulk-orders-notes',
			[__CLASS__, 'render_notes_page']
		);

		add_submenu_page(
			self::MENU_SLUG,
			'لاگ امور صورت گرفته',
			'لاگ امور صورت گرفته',
			self::CAP,
			'fa-bulk-orders-log',
			[__CLASS__, 'render_log_page']
		);
	}

	/* -------------------- CSS -------------------- */
	public static function enqueue_css() {
		if (empty($_GET['page'])) return;
		$page = sanitize_text_field($_GET['page']);
		$allowed = [self::MENU_SLUG, 'fa-bulk-orders-notes', 'fa-bulk-orders-log'];
		if (!in_array($page, $allowed, true)) return;

		$css = "
		.fa-wrap{max-width:1260px;margin:18px auto 30px;padding:0 14px}
		.fa-head{display:flex;align-items:flex-start;justify-content:space-between;gap:14px;flex-wrap:wrap;margin:10px 0 14px}
		.fa-title{font-size:20px;font-weight:900;margin:0}

		.fa-card{background:#fff;border:1px solid #e5e7eb;border-radius:16px;padding:16px 16px 14px;box-shadow:0 10px 30px rgba(17,24,39,.06);position:relative}
		.fa-card:before{content:'';position:absolute;inset:0 0 auto 0;height:5px;border-radius:16px 16px 0 0;background:linear-gradient(90deg,#6442fc,#4300cd)}

		.fa-row{display:grid;grid-template-columns:280px 1fr;gap:12px;align-items:start;margin-top:10px}
		@media(max-width:940px){.fa-row{grid-template-columns:1fr}}

		.fa-label{font-weight:900;margin-top:4px;font-size:14px} /* ✅ طبق درخواست */
		.fa-help{color:#6b7280;line-height:1.95;margin-top:6px}

		.fa-textarea{width:100%;min-height:120px;border:1px solid #d1d5db;border-radius:14px;padding:10px 12px;font-size:14px;line-height:1.95;box-sizing:border-box;outline:none}
		.fa-textarea:focus{border-color:#6442fc;box-shadow:0 0 0 3px rgba(100,66,252,.12)}
		.fa-textarea.small{min-height:70px}

		.fa-select{width:100%;border:1px solid #d1d5db;border-radius:14px;padding:10px 12px;font-size:14px;box-sizing:border-box;outline:none;background:#fff}
		.fa-select:focus{border-color:#6442fc;box-shadow:0 0 0 3px rgba(100,66,252,.12)}

		.fa-radio{display:flex;gap:16px;align-items:center;flex-wrap:wrap;margin-top:6px}

		.fa-confirm{display:flex;gap:10px;align-items:flex-start;margin-top:12px;padding:12px 12px;border:1px dashed #d1d5db;border-radius:14px;background:#fafafa}
		.fa-confirm input{transform:scale(1.15);margin-top:3px}

		.fa-actions{display:flex;justify-content:flex-end;gap:10px;flex-wrap:wrap;margin-top:14px}
		.fa-btn{border:0;border-radius:14px;padding:10px 14px;font-weight:900;cursor:pointer}
		.fa-btn.primary{background:#6442fc;color:#fff}
		.fa-btn.dark{background:#111827;color:#fff}
		.fa-btn.light{background:#f3f4f6;color:#111827}
		.fa-btn.danger{background:#b91c1c;color:#fff}
		.fa-btn[disabled]{opacity:.45;cursor:not-allowed}

		.fa-progress{margin-top:12px;background:#f3f4f6;border-radius:999px;overflow:hidden;border:1px solid #e5e7eb}
		.fa-bar{height:14px;width:0%;background:#22c55e;transition:width .2s ease}

		.fa-status{margin-top:10px;line-height:1.95}
		.fa-runmeta{margin-top:10px;border:1px solid #e5e7eb;border-radius:14px;padding:10px 12px;background:#fbfbff;line-height:1.95;display:flex;gap:10px;flex-wrap:wrap;align-items:center;justify-content:space-between}
		.fa-runmeta .fa-meta-item{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
		.fa-runmeta strong{color:#111827}
		.fa-chip{display:inline-block;border-radius:999px;padding:4px 10px;font-weight:900;font-size:12px;background:#eef2ff;border:1px solid #c7d2fe}

		.fa-summary{margin-top:10px;border:1px solid #e5e7eb;border-radius:14px;padding:10px 12px;background:#fbfbff;line-height:1.95}
		.fa-summary strong{color:#111827}

		.fa-badge{display:inline-block;border-radius:999px;padding:4px 10px;font-weight:900;font-size:12px}
		.fa-ok{background:#dcfce7;border:1px solid #86efac}
		.fa-no{background:#fee2e2;border:1px solid #fca5a5}
		.fa-warn{background:#ffedd5;border:1px solid #fdba74}

		.fa-errors{margin-top:12px;border:1px solid #fecaca;background:#fff1f2;border-radius:14px;padding:12px}
		.fa-errors h3{margin:0 0 8px;font-size:14px}
		.fa-errors ul{margin:0;padding:0 18px;line-height:2}

		.fa-copywrap{margin-top:12px;display:flex;justify-content:flex-end;gap:10px;flex-wrap:wrap}
		.fa-copybox{margin-top:10px;border:1px solid #e5e7eb;border-radius:14px;padding:10px 12px;background:#fff;line-height:1.9;white-space:pre-wrap;word-break:break-word}

		.fa-filterbar{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-top:10px}
		.fa-filterbar input,.fa-filterbar select{border:1px solid #d1d5db;border-radius:14px;padding:8px 10px}

		.fa-smallopt{display:flex;gap:10px;align-items:center;margin-top:10px;padding:10px 12px;border:1px solid #e5e7eb;border-radius:14px;background:#fcfcff}
		.fa-smallopt input{transform:scale(1.1)}

		/* Modal */
		.fa-modal-backdrop{position:fixed;inset:0;background:rgba(17,24,39,.55);z-index:99990;display:none}
		.fa-modal{position:fixed;left:50%;top:50%;transform:translate(-50%,-50%);width:min(980px,calc(100% - 24px));max-height:calc(100% - 24px);background:#fff;border-radius:18px;border:1px solid #e5e7eb;box-shadow:0 30px 90px rgba(0,0,0,.25);z-index:99999;display:none;overflow:hidden}
		.fa-modal-hd{padding:12px 14px;background:linear-gradient(90deg,rgba(100,66,252,.12),rgba(67,0,205,.06));display:flex;align-items:center;justify-content:space-between;gap:12px}
		.fa-modal-title{font-weight:900;margin:0;font-size:14px}
		.fa-modal-close{border:0;background:transparent;font-weight:900;cursor:pointer;padding:6px 10px;border-radius:10px}
		.fa-modal-close:hover{background:rgba(17,24,39,.06)}
		.fa-modal-bd{padding:12px 14px;overflow:auto;max-height:calc(100vh - 220px)}
		.fa-modal-ft{padding:12px 14px;border-top:1px solid #e5e7eb;display:flex;justify-content:flex-end;gap:10px;flex-wrap:wrap;background:#fff}
		.fa-table{width:100%;border-collapse:separate;border-spacing:0;overflow:hidden;border-radius:16px;border:1px solid #e5e7eb}
		.fa-table th,.fa-table td{padding:10px 12px;border-bottom:1px solid #e5e7eb;text-align:right;vertical-align:top;line-height:1.9}
		.fa-table thead th{background:#f9fafb;font-weight:900}
		.fa-table tr:last-child td{border-bottom:0}
		.fa-muted{color:#6b7280}
		";

		wp_register_style('fa-bulk-orders-css', false);
		wp_enqueue_style('fa-bulk-orders-css');
		wp_add_inline_style('fa-bulk-orders-css', $css);
	}

	/* -------------------- Utils -------------------- */
	private static function wc_required_or_die() {
		if (!function_exists('wc_get_order')) {
			echo '<div class="notice notice-error"><p>ووکامرس فعال نیست یا درست بارگذاری نشده است.</p></div>';
			return false;
		}
		return true;
	}

	private static function fa_to_en_digits($value) {
		$value = (string)$value;
		$fa = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
		$ar = ['٠','١','٢','٣','٤','٥','٦','٧','٨','٩'];
		$en = ['0','1','2','3','4','5','6','7','8','9'];
		$value = str_replace($fa, $en, $value);
		$value = str_replace($ar, $en, $value);
		$value = str_replace('،', ',', $value);
		return $value;
	}

	private static function en_to_fa_digits($value) {
		$value = (string)$value;
		$en = ['0','1','2','3','4','5','6','7','8','9'];
		$fa = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
		return str_replace($en, $fa, $value);
	}

	private static function current_user_is_admin_role() {
		$u = wp_get_current_user();
		if (!$u || empty($u->roles) || !is_array($u->roles)) return false;
		return in_array('administrator', $u->roles, true);
	}

	/* -------------------- Jalali (no deps) -------------------- */
	private static function gregorian_to_jalali($gy, $gm, $gd) {
		$g_d_m = [0,31,59,90,120,151,181,212,243,273,304,334];
		if($gy > 1600){ $jy=979; $gy-=1600; } else { $jy=0; $gy-=621; }
		$gy2 = ($gm > 2) ? ($gy + 1) : $gy;
		$days = (365*$gy) + (int)(($gy2 + 3)/4) - (int)(($gy2 + 99)/100) + (int)(($gy2 + 399)/400) - 80 + $gd + $g_d_m[$gm-1];
		$jy += 33*(int)($days/12053); $days %= 12053;
		$jy += 4*(int)($days/1461); $days %= 1461;
		if($days > 365){ $jy += (int)(($days - 1)/365); $days = ($days - 1) % 365; }
		$jm = ($days < 186) ? 1 + (int)($days/31) : 7 + (int)(($days - 186)/30);
		$jd = 1 + (($days < 186) ? ($days % 31) : (($days - 186) % 30));
		return [$jy, $jm, $jd];
	}

	private static function jalali_month_name($jm) {
		$names = [
			1=>'فروردین',2=>'اردیبهشت',3=>'خرداد',4=>'تیر',5=>'مرداد',6=>'شهریور',
			7=>'مهر',8=>'آبان',9=>'آذر',10=>'دی',11=>'بهمن',12=>'اسفند'
		];
		return $names[(int)$jm] ?? '';
	}

	private static function jalali_human_from_timestamp($ts) {
		// timestamp should be local timestamp (based on wp timezone)
		$dt = new DateTime('@'.$ts);
		$tz = wp_timezone();
		$dt->setTimezone($tz);

		$gy = (int)$dt->format('Y');
		$gm = (int)$dt->format('m');
		$gd = (int)$dt->format('d');
		$h  = $dt->format('H');
		$i  = $dt->format('i');

		[$jy,$jm,$jd] = self::gregorian_to_jalali($gy,$gm,$gd);
		$month = self::jalali_month_name($jm);

		$out = $jd.' '.$month.' '.$jy.' ساعت '.$h.':'.$i;
		return self::en_to_fa_digits($out);
	}

	private static function jalali_human_from_wc_date($dt) {
		if (!$dt) return '—';
		// WC_DateTime -> timestamp (in WP timezone)
		$ts = $dt->getTimestamp();
		return self::jalali_human_from_timestamp($ts);
	}

	/* -------------------- Safe Lock -------------------- */
	private static function lock_get() {
		$lock = get_transient(self::LOCK_KEY);
		return is_array($lock) ? $lock : null;
	}

	private static function lock_remaining_seconds($lock) {
		$until = isset($lock['until']) ? (int)$lock['until'] : 0;
		$now = current_time('timestamp');
		return max(0, $until - $now);
	}

	private static function lock_is_active_for_other_user(&$lock_out = null) {
		$lock = self::lock_get();
		if (!$lock) return false;

		$rem = self::lock_remaining_seconds($lock);
		if ($rem <= 0) {
			delete_transient(self::LOCK_KEY);
			return false;
		}

		$lock_out = $lock;

		$current = get_current_user_id();
		$owner_id = (int)($lock['user_id'] ?? 0);

		return ($owner_id && $current && $owner_id !== $current);
	}

	private static function lock_acquire($mode, $operation_id = '') {
		$current_id = get_current_user_id();
		$current_user = $current_id ? get_userdata($current_id) : null;

		if (!$current_id || !$current_user) {
			return ['ok'=>false,'message'=>'کاربر نامعتبر است.'];
		}

		$existing = self::lock_get();
		if ($existing) {
			$rem = self::lock_remaining_seconds($existing);
			if ($rem > 0) {
				$owner_id = (int)($existing['user_id'] ?? 0);
				if ($owner_id && $owner_id !== $current_id) {
					return [
						'ok'=>false,
						'message'=>'این صفحه در حال حاضر توسط ادمین دیگری در حال استفاده است.',
						'lock'=>$existing,
						'remaining'=>$rem,
					];
				}
			} else {
				delete_transient(self::LOCK_KEY);
			}
		}

		$until = current_time('timestamp') + self::LOCK_TTL_SECS;
		$lock = [
			'user_id'   => $current_id,
			'user_name' => $current_user->display_name,
			'mode'      => sanitize_text_field($mode),
			'operation_id' => sanitize_text_field($operation_id),
			'started_at'=> current_time('mysql'),
			'until'     => $until,
		];

		set_transient(self::LOCK_KEY, $lock, self::LOCK_TTL_SECS);

		return ['ok'=>true,'lock'=>$lock,'remaining'=>self::LOCK_TTL_SECS];
	}

	private static function lock_release() {
		$existing = self::lock_get();
		if (!$existing) return true;

		$current_id = get_current_user_id();
		$owner_id = (int)($existing['user_id'] ?? 0);

		if ($owner_id && $current_id && $owner_id !== $current_id) return false;

		delete_transient(self::LOCK_KEY);
		return true;
	}

	/* -------------------- Log (save + prune) -------------------- */
	private static function prune_logs_if_needed() {
		if (get_transient('fa_bulk_log_prune_daily')) return;
		set_transient('fa_bulk_log_prune_daily', 1, DAY_IN_SECONDS);

		$cutoff = date('Y-m-d H:i:s', strtotime('-30 days', current_time('timestamp')));

		$q = new WP_Query([
			'post_type'      => self::LOG_CPT,
			'post_status'    => 'publish',
			'fields'         => 'ids',
			'posts_per_page' => 800,
			'date_query'     => [
				[
					'column' => 'post_date',
					'before' => $cutoff,
					'inclusive' => true,
				]
			],
		]);

		if (!empty($q->posts)) {
			foreach ($q->posts as $pid) {
				wp_delete_post($pid, true);
			}
		}
	}

	private static function save_log($meta) {
		self::prune_logs_if_needed();

		$user_id = get_current_user_id();
		$user = $user_id ? get_userdata($user_id) : null;

		$type     = sanitize_text_field($meta['type'] ?? '');
		$order_id = absint($meta['order_id'] ?? 0);
		$success  = !empty($meta['success']);
		$message  = sanitize_text_field($meta['message'] ?? '');

		$opid = sanitize_text_field($meta['operation_id'] ?? '');
		$opid_short = $opid ? (' — '.$opid) : '';

		$title = 'لاگ: ' . ($type === 'status' ? 'تغییر وضعیت' : 'یادداشت')
		       . ' — سفارش #' . $order_id
		       . ' — ' . ($success ? 'موفق' : 'ناموفق')
		       . $opid_short;

		$post_id = wp_insert_post([
			'post_type'   => self::LOG_CPT,
			'post_status' => 'publish',
			'post_title'  => $title,
			'post_content'=> $message,
		]);

		if (is_wp_error($post_id) || !$post_id) return;

		$meta['user_id']   = $user_id;
		$meta['user_name'] = $user ? $user->display_name : '';
		$meta['logged_at'] = current_time('mysql');

		update_post_meta($post_id, '_fa_log', $meta);
	}

	/* -------------------- Pages -------------------- */
	private static function render_lock_block_if_needed() {
		$lock = null;
		if (self::lock_is_active_for_other_user($lock)) {
			$rem = self::lock_remaining_seconds($lock);
			$mins = max(1, (int)ceil($rem / 60));
			$owner = esc_html($lock['user_name'] ?? 'ادمین دیگر');
			$mode  = esc_html(($lock['mode'] ?? '') === 'note' ? 'افزودن یادداشت' : 'تغییر وضعیت');
			$opid  = esc_html($lock['operation_id'] ?? '');

			echo '<div class="notice notice-error"><p>';
			echo '<strong>این بخش موقتاً قفل است.</strong> ';
			echo 'در حال حاضر «'.$owner.'» در حال انجام عملیات «'.$mode.'» است. ';
			if ($opid) echo 'شناسه عملیات: <strong>'.$opid.'</strong>. ';
			echo 'لطفاً حدوداً <strong>'.$mins.' دقیقه</strong> دیگر مجدد مراجعه کنید.';
			echo '</p></div>';

			echo '<div class="fa-card"><p style="margin:0;line-height:2;color:#6b7280">';
			echo 'برای جلوگیری از اعمال همزمان تغییرات و خطاهای احتمالی، این صفحه تا پایان عملیات یا نهایتاً ۵ دقیقه قفل می‌شود.';
			echo '</p></div>';

			return true;
		}
		return false;
	}

	private static function render_modal_shell() {
		echo '<div class="fa-modal-backdrop"></div>';
		echo '<div class="fa-modal" role="dialog" aria-modal="true" aria-hidden="true">';
		echo '  <div class="fa-modal-hd">';
		echo '    <h3 class="fa-modal-title">پیش‌نمایش سفارشات</h3>';
		echo '    <button type="button" class="fa-modal-close">بستن</button>';
		echo '  </div>';
		echo '  <div class="fa-modal-bd">';
		echo '    <div class="fa-muted">در حال دریافت اطلاعات…</div>';
		echo '  </div>';
		echo '  <div class="fa-modal-ft">';
		echo '    <button type="button" class="fa-btn light fa-modal-cancel">انصراف</button>';
		echo '    <button type="button" class="fa-btn primary fa-modal-approve">تأیید پیش‌نمایش و فعال‌سازی شروع</button>';
		echo '  </div>';
		echo '</div>';
	}

	public static function render_status_page() {
		if (!current_user_can(self::CAP)) wp_die('دسترسی ندارید.');
		if (!self::wc_required_or_die()) return;

		echo '<div class="wrap fa-wrap">';
		echo '<div class="fa-head"><div>';
		echo '<h1 class="fa-title">تغییر وضعیت گروهی سفارشات</h1>';
		echo '</div></div>';

		if (self::render_lock_block_if_needed()) { echo '</div>'; return; }

		$statuses = function_exists('wc_get_order_statuses') ? wc_get_order_statuses() : [];

		echo '<div class="fa-card" data-mode="status">';

		echo '<div class="fa-row"><div class="fa-label">شماره سفارش‌ها</div><div>';
		echo '<textarea class="fa-textarea fa-ids" placeholder="مثال: 150577,150578 یا هر کدام در یک خط (یا حتی #150577)"></textarea>';
		echo '<div class="fa-help">ورودی هوشمند است: # ها هم تشخیص داده می‌شوند. اعداد فارسی/عربی خودکار به انگلیسی تبدیل می‌شوند.</div>';
		echo '<div class="fa-summary fa-ids-summary"><span class="fa-muted">هنوز چیزی وارد نشده است.</span></div>';
		echo '</div></div>';

		echo '<div class="fa-row"><div class="fa-label">انتخاب وضعیت جدید</div><div>';
		echo '<select class="fa-select fa-new-status">';
		echo '<option value="">— انتخاب کنید —</option>';
		foreach ($statuses as $k => $label) echo '<option value="'.esc_attr($k).'">'.esc_html($label).'</option>';
		echo '</select>';
		echo '</div></div>';

		echo '<div class="fa-smallopt">';
		echo '<label style="display:flex;gap:10px;align-items:center">';
		echo '<input type="checkbox" class="fa-suppress-email"> ';
		echo '<strong>عدم ارسال ایمیل</strong>';
		echo '</label>';
		echo '<span class="fa-help" style="margin:0">اگر فعال شود، هنگام تغییر وضعیت، ایمیل‌های ووکامرس برای این تغییر ارسال نمی‌شوند.</span>';
		echo '</div>';

		echo '<div class="fa-row"><div class="fa-label">علت تغییر (الزامی)</div><div>';
		echo '<textarea class="fa-textarea small fa-change-reason" placeholder="علت را وارد کنید…"></textarea>';
		echo '</div></div>';

		echo '<div class="fa-confirm">';
		echo '<input type="checkbox" class="fa-confirm-cb" id="fa_confirm_status">';
		echo '<label for="fa_confirm_status"><strong>از اعمال این تغییرات مطمئن هستم، سفارشات رو بطور دقیق بررسی کردم و احتمال اشتباه وجود ندارد.</strong></label>';
		echo '</div>';

		echo '<div class="fa-actions">';
		echo '<button type="button" class="fa-btn light fa-stop" disabled>توقف فوری</button>';
		echo '<button type="button" class="fa-btn light fa-preview" disabled>نمایش پیش نمایش سفارشات</button>';
		echo '<button type="button" class="fa-btn primary fa-start" disabled>ذخیره و شروع اعمال تغییرات!</button>';
		echo '</div>';

		echo '<div class="fa-runmeta" style="display:none">';
		echo '  <div class="fa-meta-item"><span class="fa-chip">شناسه عملیات</span> <strong class="fa-opid">—</strong></div>';
		echo '  <div class="fa-meta-item"><span class="fa-chip">تخمین زمان</span> <strong class="fa-eta">—</strong></div>';
		echo '</div>';

		echo '<div class="fa-progress"><div class="fa-bar"></div></div>';
		echo '<div class="fa-status fa-run-status"></div>';

		echo '<div class="fa-copywrap" style="display:none">';
		echo '<button type="button" class="fa-btn dark fa-copy-report">کپی گزارش</button>';
		echo '</div>';
		echo '<div class="fa-copybox" style="display:none"></div>';

		echo '</div>';

		self::render_modal_shell();
		self::print_inline_js();
		echo '</div>';
	}

	public static function render_notes_page() {
		if (!current_user_can(self::CAP)) wp_die('دسترسی ندارید.');
		if (!self::wc_required_or_die()) return;

		echo '<div class="wrap fa-wrap">';
		echo '<div class="fa-head"><div>';
		echo '<h1 class="fa-title">افزودن یادداشت گروهی به سفارشات</h1>';
		echo '</div></div>';

		if (self::render_lock_block_if_needed()) { echo '</div>'; return; }

		echo '<div class="fa-card" data-mode="note">';

		echo '<div class="fa-row"><div class="fa-label">شماره سفارش‌ها</div><div>';
		echo '<textarea class="fa-textarea fa-ids" placeholder="مثال: 150577,150578 یا هر کدام در یک خط (یا حتی #150577)"></textarea>';
		echo '<div class="fa-help">ورودی هوشمند است. اعداد فارسی/عربی تبدیل می‌شوند. سفارش‌های کمتر از ۵ رقم نامعتبرند.</div>';
		echo '<div class="fa-summary fa-ids-summary"><span class="fa-muted">هنوز چیزی وارد نشده است.</span></div>';
		echo '</div></div>';

		echo '<div class="fa-row"><div class="fa-label">نوع یادداشت</div><div class="fa-radio">';
		echo '<label><input type="radio" name="fa_note_type" value="private" checked> یادداشت خصوصی</label>';
		echo '<label><input type="radio" name="fa_note_type" value="customer"> یادداشت خریدار</label>';
		echo '</div></div>';

		echo '<div class="fa-row"><div class="fa-label">متن یادداشت</div><div>';
		echo '<textarea class="fa-textarea fa-note-text" placeholder="متن یادداشت را وارد کنید…"></textarea>';
		echo '</div></div>';

		echo '<div class="fa-row"><div class="fa-label">علت تغییر (الزامی)</div><div>';
		echo '<textarea class="fa-textarea small fa-change-reason" placeholder="علت را وارد کنید…"></textarea>';
		echo '</div></div>';

		echo '<div class="fa-confirm">';
		echo '<input type="checkbox" class="fa-confirm-cb" id="fa_confirm_note">';
		echo '<label for="fa_confirm_note"><strong>از اعمال این تغییرات مطمئن هستم، سفارشات رو بطور دقیق بررسی کردم و احتمال اشتباه وجود ندارد.</strong></label>';
		echo '</div>';

		echo '<div class="fa-actions">';
		echo '<button type="button" class="fa-btn light fa-stop" disabled>توقف فوری</button>';
		echo '<button type="button" class="fa-btn light fa-preview" disabled>نمایش پیش نمایش سفارشات</button>';
		echo '<button type="button" class="fa-btn primary fa-start" disabled>ذخیره و شروع اعمال تغییرات!</button>';
		echo '</div>';

		echo '<div class="fa-runmeta" style="display:none">';
		echo '  <div class="fa-meta-item"><span class="fa-chip">شناسه عملیات</span> <strong class="fa-opid">—</strong></div>';
		echo '  <div class="fa-meta-item"><span class="fa-chip">تخمین زمان</span> <strong class="fa-eta">—</strong></div>';
		echo '</div>';

		echo '<div class="fa-progress"><div class="fa-bar"></div></div>';
		echo '<div class="fa-status fa-run-status"></div>';

		echo '<div class="fa-copywrap" style="display:none">';
		echo '<button type="button" class="fa-btn dark fa-copy-report">کپی گزارش</button>';
		echo '</div>';
		echo '<div class="fa-copybox" style="display:none"></div>';

		echo '</div>';

		self::render_modal_shell();
		self::print_inline_js();
		echo '</div>';
	}

	public static function render_log_page() {
		if (!current_user_can(self::CAP)) wp_die('دسترسی ندارید.');

		$type_filter    = sanitize_text_field($_GET['type'] ?? '');
		$success_filter = sanitize_text_field($_GET['success'] ?? '');
		$order_filter   = absint(self::fa_to_en_digits($_GET['order_id'] ?? ''));
		$opid_filter    = sanitize_text_field($_GET['opid'] ?? '');
		$paged = max(1, (int)($_GET['paged'] ?? 1));

		$cutoff = date('Y-m-d H:i:s', strtotime('-30 days', current_time('timestamp')));

		$meta_query = [];
		if ($type_filter === 'status' || $type_filter === 'note') {
			$meta_query[] = ['key'=>'_fa_log','value'=>'"type":"'.$type_filter.'"','compare'=>'LIKE'];
		}
		if ($success_filter === '1' || $success_filter === '0') {
			$meta_query[] = ['key'=>'_fa_log','value'=>'"success":'.($success_filter === '1' ? 'true' : 'false'),'compare'=>'LIKE'];
		}
		if ($order_filter) {
			$meta_query[] = ['key'=>'_fa_log','value'=>'"order_id":'.$order_filter,'compare'=>'LIKE'];
		}
		if ($opid_filter) {
			$meta_query[] = ['key'=>'_fa_log','value'=>'"operation_id":"'.sanitize_text_field($opid_filter).'"','compare'=>'LIKE'];
		}

		$q = new WP_Query([
			'post_type'      => self::LOG_CPT,
			'post_status'    => 'publish',
			'posts_per_page' => 50,
			'paged'          => $paged,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'date_query'     => [[ 'column'=>'post_date','after'=>$cutoff,'inclusive'=>true ]],
			'meta_query'     => $meta_query,
		]);

		$nonce = wp_create_nonce(self::NONCE);

		echo '<div class="wrap fa-wrap">';
		echo '<div class="fa-head"><div>';
		echo '<h1 class="fa-title">لاگ امور صورت گرفته (۳۰ روز اخیر)</h1>';
		echo '</div>';

		if (self::current_user_is_admin_role()) {
			echo '<div>';
			echo '<button type="button" class="fa-btn danger" id="fa_purge_logs_btn" data-nonce="'.esc_attr($nonce).'">حذف لاگ های اخیر</button>';
			echo '</div>';
		}

		echo '</div>';

		echo '<form method="get" class="fa-filterbar">';
		echo '<input type="hidden" name="page" value="fa-bulk-orders-log">';
		echo '<select name="type">';
		echo '<option value="">همه نوع ها</option>';
		echo '<option value="status" '.selected($type_filter,'status',false).'>تغییر وضعیت</option>';
		echo '<option value="note" '.selected($type_filter,'note',false).'>افزودن یادداشت</option>';
		echo '</select>';
		echo '<select name="success">';
		echo '<option value="">همه نتایج</option>';
		echo '<option value="1" '.selected($success_filter,'1',false).'>موفق</option>';
		echo '<option value="0" '.selected($success_filter,'0',false).'>ناموفق</option>';
		echo '</select>';
		echo '<input type="text" name="order_id" placeholder="شماره سفارش" value="'.esc_attr($order_filter ? (string)$order_filter : '').'">';
		echo '<input type="text" name="opid" placeholder="شناسه عملیات" value="'.esc_attr($opid_filter).'">';
		echo '<button class="button">اعمال فیلتر</button>';
		echo '</form>';

		echo '<div class="fa-card">';

		if ($q->have_posts()) {
			echo '<table class="widefat striped" style="width:100%;border-radius:14px;overflow:hidden">';
			echo '<thead><tr><th>زمان</th><th>ادمین</th><th>شناسه عملیات</th><th>سفارش</th><th>نوع</th><th>نتیجه</th><th>جزئیات دقیق</th></tr></thead><tbody>';

			while ($q->have_posts()) {
				$q->the_post();
				$meta = get_post_meta(get_the_ID(), '_fa_log', true);
				$meta = is_array($meta) ? $meta : [];

				$order_id = (int)($meta['order_id'] ?? 0);
				$type     = sanitize_text_field($meta['type'] ?? '');
				$ok       = !empty($meta['success']);
				$user     = sanitize_text_field($meta['user_name'] ?? '');
				$message  = sanitize_text_field($meta['message'] ?? '');
				$opid     = sanitize_text_field($meta['operation_id'] ?? '');

				$order_link = $order_id ? admin_url('post.php?post='.$order_id.'&action=edit') : '';

				// ✅ زمان شمسی با فرمت: ۱۲ آذر ۱۴۰۴ ساعت ۱۸:۲۰
				$ts = (int) get_post_time('U', true, get_the_ID());
				$time_j = self::jalali_human_from_timestamp($ts);

				if ($type === 'status') {
					$from = sanitize_text_field($meta['from'] ?? '');
					$to   = sanitize_text_field($meta['to'] ?? '');
					$noem = !empty($meta['suppress_email']) ? ' (عدم ارسال ایمیل)' : '';
					$reason = !empty($meta['reason_preview']) ? ' — علت: '.$meta['reason_preview'] : '';
					$details = 'تغییر وضعیت: از «'.$from.'» به «'.$to.'»'.$noem.$reason;
				} elseif ($type === 'note') {
					$nt = sanitize_text_field($meta['note_type'] ?? '');
					$note = sanitize_text_field($meta['note_preview'] ?? '');
					$reason = !empty($meta['reason_preview']) ? ' — علت: '.$meta['reason_preview'] : '';
					$details = ($nt ? $nt : 'یادداشت') . ' — ' . $note . $reason;
				} else {
					$details = $message;
				}

				echo '<tr>';
				echo '<td>'.esc_html($time_j).'</td>';
				echo '<td>'.esc_html($user ?: '-').'</td>';
				echo '<td>'.esc_html($opid ?: '-').'</td>';
				echo '<td>'.($order_id ? '<a href="'.esc_url($order_link).'">#'.esc_html($order_id).'</a>' : '-').'</td>';
				echo '<td>'.esc_html($type === 'status' ? 'تغییر وضعیت' : 'افزودن یادداشت').'</td>';
				echo '<td>'.($ok ? '<span class="fa-badge fa-ok">موفق</span>' : '<span class="fa-badge fa-no">ناموفق</span>').'</td>';
				echo '<td>'.esc_html($details).'</td>';
				echo '</tr>';
			}
			wp_reset_postdata();
			echo '</tbody></table>';

			$total_pages = (int)$q->max_num_pages;
			if ($total_pages > 1) {
				$base = add_query_arg([
					'page'=>'fa-bulk-orders-log',
					'type'=>$type_filter,
					'success'=>$success_filter,
					'order_id'=>$order_filter ?: '',
					'opid'=>$opid_filter ?: '',
					'paged'=>'%#%',
				], admin_url('admin.php'));

				echo '<div style="margin-top:12px">';
				echo paginate_links([
					'base'=>$base,
					'format'=>'',
					'current'=>$paged,
					'total'=>$total_pages,
					'prev_text'=>'قبلی',
					'next_text'=>'بعدی',
				]);
				echo '</div>';
			}
		} else {
			echo '<div class="fa-muted">لاگی در ۳۰ روز اخیر پیدا نشد.</div>';
		}

		echo '</div></div>';

		if (self::current_user_is_admin_role()) {
			$ajax = esc_js(admin_url('admin-ajax.php'));
			echo "<script>
			(function(){
			  var btn = document.getElementById('fa_purge_logs_btn');
			  if(!btn) return;
			  btn.addEventListener('click', async function(){
			    if(!confirm('آیا از حذف لاگ های اخیر مطمئن هستید؟')) return;
			    btn.disabled = true;
			    var nonce = btn.getAttribute('data-nonce');
			    try{
			      var body = new URLSearchParams({
			        action:'fa_bulk_orders_purge_logs',
			        _ajax_nonce: nonce
			      }).toString();
			      var res = await fetch('{$ajax}', {
			        method:'POST',
			        headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
			        body: body,
			        credentials:'same-origin'
			      });
			      var json = await res.json();
			      if(json && json.success){
			        alert('لاگ های اخیر حذف شدند.');
			        location.reload();
			      } else {
			        var msg = (json && json.data && json.data.message) ? json.data.message : 'خطا در حذف لاگ ها';
			        alert(msg);
			        btn.disabled = false;
			      }
			    }catch(e){
			      alert('خطای ارتباط');
			      btn.disabled = false;
			    }
			  });
			})();</script>";
		}
	}

	/* -------------------- Ajax: Lock -------------------- */
	public static function ajax_lock_acquire() {
		check_ajax_referer(self::NONCE);

		if (!current_user_can(self::CAP)) wp_send_json_error(['message'=>'دسترسی ندارید.'], 403);

		$mode = sanitize_text_field($_POST['mode'] ?? '');
		$operation_id = sanitize_text_field($_POST['operation_id'] ?? '');

		$r = self::lock_acquire($mode, $operation_id);

		if (empty($r['ok'])) {
			$lock = $r['lock'] ?? null;
			$rem = (int)($r['remaining'] ?? 0);
			wp_send_json_error([
				'message' => $r['message'] ?? 'قفل فعال است.',
				'lock'    => $lock,
				'remaining' => $rem,
			], 409);
		}

		wp_send_json_success([
			'lock' => $r['lock'],
			'remaining' => (int)($r['remaining'] ?? self::LOCK_TTL_SECS),
		]);
	}

	public static function ajax_lock_release() {
		check_ajax_referer(self::NONCE);

		if (!current_user_can(self::CAP)) wp_send_json_error(['message'=>'دسترسی ندارید.'], 403);

		$ok = self::lock_release();
		if (!$ok) wp_send_json_error(['message'=>'شما مالک قفل نیستید.'], 403);

		wp_send_json_success(['message'=>'قفل آزاد شد.']);
	}

	public static function ajax_lock_status() {
		check_ajax_referer(self::NONCE);

		if (!current_user_can(self::CAP)) wp_send_json_error(['message'=>'دسترسی ندارید.'], 403);

		$lock = self::lock_get();
		if (!$lock) wp_send_json_success(['active'=>false]);

		$rem = self::lock_remaining_seconds($lock);
		if ($rem <= 0) {
			delete_transient(self::LOCK_KEY);
			wp_send_json_success(['active'=>false]);
		}

		wp_send_json_success(['active'=>true,'lock'=>$lock,'remaining'=>$rem]);
	}

	/* -------------------- Ajax: Preview -------------------- */
	public static function ajax_preview() {
		check_ajax_referer(self::NONCE);

		if (!current_user_can(self::CAP)) wp_send_json_error(['message'=>'دسترسی ندارید.'], 403);
		if (!function_exists('wc_get_order')) wp_send_json_error(['message'=>'ووکامرس فعال نیست.'], 400);

		$ids_csv = isset($_POST['ids_csv']) ? (string)$_POST['ids_csv'] : '';
		$ids_csv = self::fa_to_en_digits($ids_csv);
		$ids_csv = preg_replace('/[^0-9,]/', '', $ids_csv);
		$raw = array_filter(array_map('trim', explode(',', $ids_csv)));

		$valid = [];
		$seen = [];
		foreach ($raw as $id) {
			$id = absint($id);
			if (!$id || $id < self::MIN_ORDER_ID) continue;
			if (isset($seen[$id])) continue;
			$seen[$id] = true;
			$valid[] = $id;
		}

		$count = count($valid);
		if ($count < self::MIN_COUNT || $count > self::MAX_COUNT) {
			wp_send_json_error([
				'message' => 'تعداد سفارش های معتبر باید بین ۲ تا ۱۰۰ باشد.',
				'count'   => $count,
			], 400);
		}

		$statuses = function_exists('wc_get_order_statuses') ? wc_get_order_statuses() : [];
		$rows = [];
		$errors = [];

		foreach ($valid as $oid) {
			$order = wc_get_order($oid);
			if (!$order) { $errors[] = ['order_id'=>$oid,'message'=>'سفارش یافت نشد']; continue; }

			$status_key = 'wc-' . $order->get_status();
			$status_lbl = $statuses[$status_key] ?? $status_key;

			$total = function_exists('wc_price')
				? wp_strip_all_tags(wc_price($order->get_total(), ['currency'=>$order->get_currency()]))
				: (string)$order->get_total();

			$dt = $order->get_date_created();
			$date_display = self::jalali_human_from_wc_date($dt);

			$last_note = '—';
			if (function_exists('wc_get_order_notes')) {
				$notes = wc_get_order_notes([
					'order_id' => $oid,
					'limit'    => 1,
					'orderby'  => 'date_created',
					'order'    => 'DESC',
				]);
				if (!empty($notes) && is_array($notes)) {
					$content = $notes[0]->content ?? '';
					$content = wp_strip_all_tags((string)$content);
					$content = trim($content);
					$last_note = $content ? mb_strimwidth($content, 0, 160, '…') : '—';
				}
			}

			$rows[] = [
				'order_id' => self::en_to_fa_digits((string)$oid),
				'date'     => $date_display,
				'status'   => $status_lbl,
				'total'    => $total,
				'last_note'=> $last_note,
				'edit_url' => admin_url('post.php?post='.$oid.'&action=edit'),
			];
		}

		wp_send_json_success(['count'=>$count,'rows'=>$rows,'errors'=>$errors]);
	}

	/* -------------------- Ajax: Do One -------------------- */
	public static function ajax_do_one() {
		check_ajax_referer(self::NONCE);

		if (!current_user_can(self::CAP)) wp_send_json_error(['message' => 'دسترسی ندارید.'], 403);
		if (!function_exists('wc_get_order')) wp_send_json_error(['message' => 'ووکامرس فعال نیست.'], 400);

		$mode = sanitize_text_field($_POST['mode'] ?? '');
		$operation_id = sanitize_text_field($_POST['operation_id'] ?? '');
		$order_id = absint(self::fa_to_en_digits($_POST['order_id'] ?? ''));

		if (!$order_id || $order_id < self::MIN_ORDER_ID) {
			wp_send_json_error(['message' => 'شماره سفارش نامعتبر است (باید حداقل ۵ رقمی باشد).'], 400);
		}

		$order = wc_get_order($order_id);
		if (!$order) {
			self::save_log(['type'=>$mode,'order_id'=>$order_id,'operation_id'=>$operation_id,'success'=>false,'message'=>'سفارش یافت نشد.']);
			wp_send_json_error(['message' => 'سفارش یافت نشد.'], 404);
		}

		$reason = sanitize_text_field($_POST['change_reason'] ?? '');
		$reason = trim($reason);
		if ($reason === '') {
			self::save_log(['type'=>$mode,'order_id'=>$order_id,'operation_id'=>$operation_id,'success'=>false,'message'=>'علت تغییر خالی است.']);
			wp_send_json_error(['message' => 'علت تغییر الزامی است.'], 400);
		}

		try {
			if ($mode === 'status') { self::do_change_status($order, $operation_id); wp_send_json_success(['message' => 'انجام شد.']); }
			if ($mode === 'note')   { self::do_add_note($order, $operation_id);     wp_send_json_success(['message' => 'انجام شد.']); }

			wp_send_json_error(['message' => 'درخواست نامعتبر است.'], 400);

		} catch (Exception $e) {
			self::save_log(['type'=>$mode,'order_id'=>$order_id,'operation_id'=>$operation_id,'success'=>false,'message'=>$e->getMessage()]);
			wp_send_json_error(['message' => $e->getMessage()], 500);
		}
	}

	/* -------------------- Email Suppression Helper -------------------- */
	private static function suppress_emails_begin() {
		if (!function_exists('WC')) return null;
		$mailer = WC()->mailer();
		if (!$mailer) return null;

		$emails = $mailer->get_emails();
		if (!is_array($emails)) return null;

		$removed = [];
		foreach ($emails as $id => $email) {
			if (!is_object($email)) continue;
			$actions = isset($email->actions) && is_array($email->actions) ? $email->actions : [];
			if (!$actions) continue;

			foreach ($actions as $hook) {
				if (has_action($hook, [$email, 'trigger'])) {
					remove_action($hook, [$email, 'trigger'], 10);
					$removed[] = [$hook, $email];
				}
			}
		}
		return $removed;
	}

	private static function suppress_emails_end($removed) {
		if (empty($removed) || !is_array($removed)) return;
		foreach ($removed as $row) {
			$hook = $row[0] ?? '';
			$email = $row[1] ?? null;
			if ($hook && $email && is_object($email)) add_action($hook, [$email, 'trigger'], 10, 2);
		}
	}

	private static function do_change_status($order, $operation_id) {
		$new_status = sanitize_text_field($_POST['new_status'] ?? '');
		if (!$new_status) throw new Exception('وضعیت جدید انتخاب نشده است.');

		$statuses = function_exists('wc_get_order_statuses') ? wc_get_order_statuses() : [];
		if (empty($statuses[$new_status])) throw new Exception('وضعیت انتخاب شده معتبر نیست.');

		$from_key = 'wc-' . $order->get_status();
		$to_key   = $new_status;

		$from_label = $statuses[$from_key] ?? $from_key;
		$to_label   = $statuses[$to_key] ?? $to_key;

		$suppress_email = !empty($_POST['suppress_email']);

		$reason = sanitize_text_field($_POST['change_reason'] ?? '');
		$reason = trim($reason);
		if ($reason === '') throw new Exception('علت تغییر الزامی است.');

		$reason_preview = mb_strimwidth($reason, 0, 120, '…');

		$removed = null;
		if ($suppress_email) $removed = self::suppress_emails_begin();

		$order->set_status(str_replace('wc-','',$to_key));
		$order->save();

		if ($suppress_email) self::suppress_emails_end($removed);

		$user = wp_get_current_user();
		$op = $operation_id ? (' | شناسه عملیات: '.$operation_id) : '';
		$note = 'تغییر وضعیت گروهی توسط «'.$user->display_name.'»؛ از «'.$from_label.'» به «'.$to_label.'». علت: '.$reason.$op;
		$order->add_order_note($note, false, true);

		self::save_log([
			'type'=>'status',
			'order_id'=>$order->get_id(),
			'operation_id'=>$operation_id,
			'from'=>$from_label,
			'to'=>$to_label,
			'suppress_email'=>$suppress_email ? 1 : 0,
			'reason_preview'=>$reason_preview,
			'success'=>true,
			'message'=>'انجام شد.'
		]);
	}

	private static function do_add_note($order, $operation_id) {
		$note_type = sanitize_text_field($_POST['note_type'] ?? 'private'); // private|customer
		$note_text = wp_kses_post((string)($_POST['note_text'] ?? ''));
		if (trim($note_text) === '') throw new Exception('متن یادداشت خالی است.');

		$is_customer = ($note_type === 'customer');

		$reason = sanitize_text_field($_POST['change_reason'] ?? '');
		$reason = trim($reason);
		if ($reason === '') throw new Exception('علت تغییر الزامی است.');
		$reason_preview = mb_strimwidth($reason, 0, 120, '…');

		$args = [
			'order_id'        => $order->get_id(),
			'current_user_id' => get_current_user_id(),
			'note'            => $note_text,
			'note_content'    => $note_text,
			'is_customer'     => $is_customer,
			'customer_note'   => $is_customer,
			'note_type'       => $is_customer ? 'customer' : 'private',
		];

		$args = apply_filters('rdwceon_get_order_note_args', $args);

		$final_note = (string)($args['note'] ?? ($args['note_content'] ?? $note_text));

		if (isset($args['is_customer'])) {
			$final_is_customer = (bool)$args['is_customer'];
		} elseif (isset($args['customer_note'])) {
			$final_is_customer = (bool)$args['customer_note'];
		} elseif (isset($args['note_type'])) {
			$final_is_customer = ((string)$args['note_type'] === 'customer');
		} else {
			$final_is_customer = $is_customer;
		}

		$order->add_order_note($final_note, $final_is_customer, true);

		$user = wp_get_current_user();
		$op = $operation_id ? (' | شناسه عملیات: '.$operation_id) : '';
		$order->add_order_note('علت ثبت یادداشت (گروهی) توسط «'.$user->display_name.'»: '.$reason.$op, false, true);

		$preview = wp_strip_all_tags($final_note);
		$preview = mb_strimwidth(trim($preview), 0, 180, '…');

		self::save_log([
			'type'=>'note',
			'order_id'=>$order->get_id(),
			'operation_id'=>$operation_id,
			'note_type'=>$final_is_customer ? 'یادداشت خریدار' : 'یادداشت خصوصی',
			'note_preview'=>$preview,
			'reason_preview'=>$reason_preview,
			'success'=>true,
			'message'=>'انجام شد.'
		]);
	}

	/* -------------------- Ajax: Purge logs (admin role only) -------------------- */
	public static function ajax_purge_logs() {
		check_ajax_referer(self::NONCE);

		if (!self::current_user_is_admin_role()) {
			wp_send_json_error(['message'=>'فقط ادمین اجازه حذف لاگ ها را دارد.'], 403);
		}

		$cutoff = date('Y-m-d H:i:s', strtotime('-30 days', current_time('timestamp')));

		$q = new WP_Query([
			'post_type'      => self::LOG_CPT,
			'post_status'    => 'publish',
			'fields'         => 'ids',
			'posts_per_page' => 2000,
			'date_query'     => [[ 'column'=>'post_date','after'=>$cutoff,'inclusive'=>true ]],
		]);

		$deleted = 0;
		if (!empty($q->posts)) {
			foreach ($q->posts as $pid) {
				wp_delete_post($pid, true);
				$deleted++;
			}
		}

		wp_send_json_success(['deleted'=>$deleted]);
	}

	/* -------------------- Inline JS -------------------- */
	private static function print_inline_js() {
		if (empty($_GET['page'])) return;
		$page = sanitize_text_field($_GET['page']);
		$allowed = [self::MENU_SLUG, 'fa-bulk-orders-notes'];
		if (!in_array($page, $allowed, true)) return;

		$ajax  = esc_js(admin_url('admin-ajax.php'));
		$nonce = esc_js(wp_create_nonce(self::NONCE));

		$minId = (int) self::MIN_ORDER_ID;
		$minCount = (int) self::MIN_COUNT;
		$maxCount = (int) self::MAX_COUNT;

		echo "<script>
(function(){
  var MIN_ID = {$minId};
  var MIN_COUNT = {$minCount};
  var MAX_COUNT = {$maxCount};

  // ✅ برای کاهش فشار روی سرور (بهینه و امن)
  var BATCH_SIZE = 7;          // کوچکتر و امن تر
  var BATCH_PAUSE_MS = 900;    // مکث کوتاه
  var BETWEEN_REQUESTS_MS = 80;

  function toEnglishDigits(str){
    if(!str) return '';
    str = String(str);
    str = str.replace(/[۰-۹]/g, function(d){ return '۰۱۲۳۴۵۶۷۸۹'.indexOf(d); });
    str = str.replace(/[٠-٩]/g, function(d){ return '٠١٢٣٤٥٦٧٨٩'.indexOf(d); });
    str = str.replace(/،/g, ',');
    return str;
  }

  function extractNumberToken(token){
    if(!token) return null;
    token = toEnglishDigits(token);
    var m = token.match(/(\\d{1,})/);
    return m ? m[1] : null;
  }

  function parseIds(text){
    if(!text) return {valid:[], invalid:[], deduped:[], tooSmall:[]};

    text = toEnglishDigits(text).replace(/\\r/g,'\\n');
    var raw = text.split(/[\\n,;\\s]+/g).filter(Boolean);

    var valid = [];
    var invalid = [];
    var deduped = [];
    var tooSmall = [];
    var seen = {};

    for(var i=0;i<raw.length;i++){
      var tok = (raw[i]||'').trim();
      if(!tok) continue;

      var num = extractNumberToken(tok); // ✅ # هم تشخیص داده میشه
      if(!num){ invalid.push(tok); continue; }
      if(!/^\\d+$/.test(num)){ invalid.push(tok); continue; }

      var n = parseInt(num,10);
      if(!n || n < MIN_ID){ tooSmall.push(num); continue; }

      if(seen[num]){ deduped.push(num); continue; }
      seen[num]=1;
      valid.push(n);
    }

    return {valid:valid, invalid:invalid, deduped:deduped, tooSmall:tooSmall};
  }

  function qs(el, sel){ return el ? el.querySelector(sel) : null; }
  function qsa(el, sel){ return el ? Array.prototype.slice.call(el.querySelectorAll(sel)) : []; }
  function setHTML(el, html){ if(el) el.innerHTML = html; }
  function esc(s){ return String(s||'').replace(/[&<>\"']/g,function(c){return({'&':'&amp;','<':'&lt;','>':'&gt;','\"':'&quot;',\"'\":'&#39;'}[c]);}); }
  function sleep(ms){ return new Promise(function(r){ setTimeout(r, ms); }); }

  function refreshSummary(card){
    var idsTa = qs(card,'.fa-ids');
    var summary = qs(card,'.fa-ids-summary');
    if(!idsTa || !summary) return;

    var parsed = parseIds(idsTa.value);
    var html = '';

    if(!idsTa.value.trim()){
      html = '<span class=\"fa-muted\">هنوز چیزی وارد نشده است.</span>';
    } else {
      html += '<div><strong>معتبر:</strong> '+ parsed.valid.length +'</div>';
      if(parsed.deduped.length) html += '<div><strong>تکراری:</strong> '+ parsed.deduped.length +' (حذف می شوند)</div>';
      if(parsed.tooSmall.length) html += '<div style=\"color:#b45309\"><strong>کمتر از ۵ رقم:</strong> '+ parsed.tooSmall.length +' (نادیده گرفته می شوند)</div>';
      if(parsed.invalid.length) html += '<div style=\"color:#b91c1c\"><strong>نامعتبر:</strong> '+ parsed.invalid.length +' (نادیده گرفته می شوند)</div>';

      if(parsed.valid.length < MIN_COUNT){
        html += '<div style=\"color:#b91c1c;margin-top:6px\"><strong>خطا:</strong> حداقل ۲ سفارش لازم است.</div>';
      } else if(parsed.valid.length > MAX_COUNT){
        html += '<div style=\"color:#b91c1c;margin-top:6px\"><strong>خطا:</strong> حداکثر ۱۰۰ سفارش مجاز است. (فعلاً '+parsed.valid.length+' سفارش معتبر دارید)</div>';
      }
    }

    setHTML(summary, html);
  }

  function canStartByCount(validCount){
    return validCount >= MIN_COUNT && validCount <= MAX_COUNT;
  }

  async function post(data, controller){
    var body = new URLSearchParams(data).toString();
    var res = await fetch('{$ajax}', {
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
      body: body,
      credentials:'same-origin',
      signal: controller ? controller.signal : undefined
    });
    return res.json();
  }

  // ---------------- Safe Lock + Refresh warning ----------------
  var running = false;

  function enableBeforeUnload(){ window.addEventListener('beforeunload', onBeforeUnload); }
  function disableBeforeUnload(){ window.removeEventListener('beforeunload', onBeforeUnload); }
  function onBeforeUnload(e){
    if(!running) return;
    e.preventDefault(); e.returnValue = ''; return '';
  }

  async function releaseLock(){
    try{ await post({action:'fa_bulk_orders_lock_release', _ajax_nonce:'{$nonce}'}); }catch(e){}
  }

  // ---------------- Preview state ----------------
  var previewApproved = false;
  var previewFingerprint = '';

  function makeFingerprint(card){
    var mode = card.getAttribute('data-mode') || '';
    var idsTa = qs(card,'.fa-ids');
    var ids = parseIds(idsTa ? idsTa.value : '').valid.join(',');
    var reason = (qs(card,'.fa-change-reason') ? qs(card,'.fa-change-reason').value.trim() : '');

    if(mode === 'status'){
      var st = qs(card,'.fa-new-status');
      var sup = qs(card,'.fa-suppress-email');
      return [mode, ids, (st?st.value:''), (sup && sup.checked ? '1':'0'), reason].join('||');
    } else {
      var noteTa = qs(card,'.fa-note-text');
      var rt = qs(card,'input[name=\"fa_note_type\"]:checked');
      return [mode, ids, (rt?rt.value:'private'), (noteTa?noteTa.value.trim():''), reason].join('||');
    }
  }

  function resetPreviewApproval(card){
    previewApproved = false;
    previewFingerprint = '';
    refreshButtons(card);
  }

  function isInputsOkForPreview(card){
    var mode = card.getAttribute('data-mode');
    var idsTa = qs(card,'.fa-ids');
    var parsed = parseIds(idsTa ? idsTa.value : '');
    if(!canStartByCount(parsed.valid.length)) return false;

    var reasonTa = qs(card,'.fa-change-reason');
    if(!reasonTa || reasonTa.value.trim().length < 2) return false;

    if(mode === 'status'){
      var st = qs(card,'.fa-new-status');
      if(!st || !st.value) return false;
      return true;
    } else {
      var noteTa = qs(card,'.fa-note-text');
      if(!noteTa || noteTa.value.trim().length < 2) return false;
      return true;
    }
  }

  function refreshButtons(card){
    var cb = qs(card,'.fa-confirm-cb');
    var previewBtn = qs(card,'.fa-preview');
    var startBtn = qs(card,'.fa-start');

    var allowPreview = !!(cb && cb.checked) && isInputsOkForPreview(card);
    if(previewBtn) previewBtn.disabled = !allowPreview;

    var allowStart = allowPreview && previewApproved && (previewFingerprint === makeFingerprint(card));
    if(startBtn) startBtn.disabled = !allowStart;
  }

  // ---------------- Modal ----------------
  function getModal(){
    return {
      backdrop: document.querySelector('.fa-modal-backdrop'),
      modal: document.querySelector('.fa-modal'),
      bd: document.querySelector('.fa-modal-bd'),
      close: document.querySelector('.fa-modal-close'),
      cancel: document.querySelector('.fa-modal-cancel'),
      approve: document.querySelector('.fa-modal-approve')
    };
  }

  function openModal(html){
    var m = getModal();
    if(!m.backdrop || !m.modal) return;
    m.bd.innerHTML = html || '<div class=\"fa-muted\">—</div>';
    m.backdrop.style.display = 'block';
    m.modal.style.display = 'block';
    m.modal.setAttribute('aria-hidden','false');
  }

  function closeModal(){
    var m = getModal();
    if(!m.backdrop || !m.modal) return;
    m.backdrop.style.display = 'none';
    m.modal.style.display = 'none';
    m.modal.setAttribute('aria-hidden','true');
  }

  (function bindModalEvents(){
    var m = getModal();
    if(!m.backdrop) return;
    m.backdrop.addEventListener('click', closeModal);
    if(m.close) m.close.addEventListener('click', closeModal);
    if(m.cancel) m.cancel.addEventListener('click', closeModal);
    document.addEventListener('keydown', function(e){ if(e.key === 'Escape') closeModal(); });
  })();

  async function showPreview(card){
    var ids = parseIds(qs(card,'.fa-ids').value).valid;
    var ids_csv = ids.join(',');

    openModal('<div class=\"fa-muted\">در حال دریافت اطلاعات…</div>');

    var res;
    try{
      res = await post({ action:'fa_bulk_orders_preview', _ajax_nonce:'{$nonce}', ids_csv: ids_csv });
    }catch(e){
      openModal('<div style=\"color:#b91c1c\"><strong>خطا در دریافت پیش نمایش</strong></div>');
      return;
    }

    if(!res || !res.success){
      var msg = (res && res.data && res.data.message) ? res.data.message : 'خطا در دریافت پیش نمایش';
      openModal('<div style=\"color:#b91c1c\"><strong>'+esc(msg)+'</strong></div>');
      return;
    }

    var data = res.data || {};
    var rows = data.rows || [];
    var errors = data.errors || [];

    var html = '';

    if(errors.length){
      html += '<div class=\"fa-errors\" style=\"margin-bottom:12px\"><h3>مشکل در برخی سفارش ها</h3><ul>';
      errors.forEach(function(er){ html += '<li>سفارش '+esc(er.order_id)+' — '+esc(er.message)+'</li>'; });
      html += '</ul></div>';
    }

    html += '<table class=\"fa-table\">';
    html += '<thead><tr><th style=\"width:120px\">شماره سفارش</th><th style=\"width:240px\">تاریخ ثبت سفارش</th><th style=\"width:170px\">وضعیت فعلی</th><th style=\"width:150px\">مبلغ سفارش</th><th>آخرین یادداشت</th><th style=\"width:90px\">لینک</th></tr></thead><tbody>';
    rows.forEach(function(r){
      html += '<tr>';
      html += '<td>#'+esc(r.order_id)+'</td>';
      html += '<td>'+esc(r.date)+'</td>';
      html += '<td>'+esc(r.status)+'</td>';
      html += '<td>'+esc(r.total)+'</td>';
      html += '<td>'+esc(r.last_note)+'</td>';
      html += '<td><a href=\"'+esc(r.edit_url)+'\" target=\"_blank\">مشاهده</a></td>';
      html += '</tr>';
    });
    html += '</tbody></table>';

    openModal(html);

    var m = getModal();
    if(m.approve){
      m.approve.onclick = function(){
        previewApproved = true;
        previewFingerprint = makeFingerprint(card);
        closeModal();
        refreshButtons(card);
      };
    }
  }

  // ---------------- Copy report ----------------
  function buildReport(mode, total, successIds, failed, options){
    var lines = [];
    lines.push(mode === 'status' ? 'گزارش تغییر وضعیت گروهی' : 'گزارش افزودن یادداشت گروهی');
    lines.push('شناسه عملیات: ' + (options && options.operationId ? options.operationId : '—'));
    lines.push('----------------------------------------');
    lines.push('تعداد سفارش های هدف: ' + total);
    lines.push('تعداد سفارش های موفق: ' + successIds.length);
    lines.push('تعداد سفارش های ناموفق: ' + failed.length);

    if(mode === 'status' && options){
      if(options.newStatusLabel) lines.push('وضعیت جدید: ' + options.newStatusLabel);
      lines.push('عدم ارسال ایمیل: ' + (options.suppressEmail ? 'فعال' : 'غیرفعال'));
    }
    if(options && options.reason){ lines.push('علت تغییر: ' + options.reason); }

    lines.push('----------------------------------------');

    lines.push('سفارش های موفق (' + successIds.length + '):');
    lines.push(successIds.length ? successIds.join(', ') : '—');

    lines.push('----------------------------------------');

    lines.push('سفارش های ناموفق (' + failed.length + '):');
    if(failed.length){
      failed.forEach(function(er){ lines.push(' - ' + er.order_id + ' => ' + er.message); });
    } else {
      lines.push('—');
    }

    return lines.join('\\n');
  }

  async function copyText(txt){
    try{ await navigator.clipboard.writeText(txt); return true; }
    catch(e){
      try{
        var ta=document.createElement('textarea'); ta.value=txt; ta.style.position='fixed'; ta.style.left='-9999px';
        document.body.appendChild(ta); ta.focus(); ta.select(); document.execCommand('copy'); document.body.removeChild(ta);
        return true;
      }catch(_e){ return false; }
    }
  }

  // ✅ قفل کارت بدون غیرفعال کردن دکمه کپی گزارش
  function lockCard(card, locked){
    qsa(card,'textarea,input,select,button').forEach(function(x){
      if(x.classList.contains('fa-stop')) return;
      if(x.classList.contains('fa-start')) return;
      if(x.classList.contains('fa-preview')) return;
      if(x.classList.contains('fa-copy-report')) return; // ✅ مهم
      x.disabled = locked;
    });
    var stop = qs(card,'.fa-stop');
    if(stop) stop.disabled = locked ? false : true;
    var previewBtn = qs(card,'.fa-preview');
    if(previewBtn) previewBtn.disabled = locked ? true : previewBtn.disabled;
  }

  // ---------------- ETA helpers ----------------
  function formatETA(ms){
    if(!isFinite(ms) || ms <= 0) return '—';
    var sec = Math.ceil(ms/1000);
    var m = Math.floor(sec/60);
    var s = sec % 60;
    // اعداد فارسی
    function fa(n){ return String(n).replace(/[0-9]/g, d=>'۰۱۲۳۴۵۶۷۸۹'[d]); }
    if(m <= 0) return fa(s) + ' ثانیه';
    return fa(m) + ' دقیقه ' + fa(s) + ' ثانیه';
  }

  function genOperationId(){
    // کوتاه، خوانا، یکتا
    var t = Date.now().toString(36).toUpperCase();
    var r = Math.random().toString(36).slice(2,8).toUpperCase();
    return 'FA-' + t + '-' + r;
  }

  // ---------------- handlers ----------------
  document.addEventListener('input', function(e){
    var card = e.target.closest('.fa-card');
    if(!card) return;

    if(e.target.classList.contains('fa-ids')){
      var fixed = toEnglishDigits(e.target.value);
      if(fixed !== e.target.value) e.target.value = fixed;
      refreshSummary(card);
      resetPreviewApproval(card);
    }

    if(e.target.classList.contains('fa-note-text') ||
       e.target.classList.contains('fa-change-reason')){
      resetPreviewApproval(card);
    }

    refreshButtons(card);
  }, true);

  document.addEventListener('change', function(e){
    var card = e.target.closest('.fa-card');
    if(!card) return;

    if(e.target.classList.contains('fa-confirm-cb') ||
       e.target.classList.contains('fa-new-status') ||
       e.target.name === 'fa_note_type' ||
       e.target.classList.contains('fa-suppress-email')){
      resetPreviewApproval(card);
    }

    refreshButtons(card);
  }, true);

  document.addEventListener('click', function(e){
    if(!e.target.classList.contains('fa-preview')) return;
    e.preventDefault();
    var card = e.target.closest('.fa-card');
    if(!card) return;

    var st = qs(card,'.fa-run-status');
    if(!isInputsOkForPreview(card)){
      if(st) setHTML(st,'<span style=\"color:#b91c1c\"><strong>برای پیش نمایش، علت تغییر و سایر فیلدهای لازم را کامل کنید.</strong></span>');
      return;
    }
    showPreview(card);
  }, true);

  document.addEventListener('click', function(e){
    if(!e.target.classList.contains('fa-start')) return;
    e.preventDefault();

    var card = e.target.closest('.fa-card');
    if(!card) return;

    var status = qs(card,'.fa-run-status');
    var bar = qs(card,'.fa-bar');
    var copyWrap = qs(card,'.fa-copywrap');
    var copyBtn = qs(card,'.fa-copy-report');
    var copyBox = qs(card,'.fa-copybox');
    var runMeta = qs(card,'.fa-runmeta');
    var opIdEl = qs(card,'.fa-opid');
    var etaEl = qs(card,'.fa-eta');

    var oldErr = qs(card,'.fa-errors');
    if(oldErr) oldErr.remove();
    if(bar) bar.style.width = '0%';
    if(status) setHTML(status,'');
    if(copyWrap) copyWrap.style.display='none';
    if(copyBox){ copyBox.style.display='none'; copyBox.textContent=''; }
    if(copyBtn) copyBtn.disabled = false;
    if(runMeta) runMeta.style.display='none';
    if(opIdEl) opIdEl.textContent = '—';
    if(etaEl) etaEl.textContent = '—';

    if(!(previewApproved && (previewFingerprint === makeFingerprint(card)))){
      if(status) setHTML(status, '<span style=\"color:#b91c1c\"><strong>ابتدا پیش نمایش را مشاهده و تایید کنید.</strong></span>');
      refreshButtons(card);
      return;
    }

    var mode = card.getAttribute('data-mode');
    var parsed = parseIds(qs(card,'.fa-ids').value);
    var ids = parsed.valid;

    if(!canStartByCount(ids.length)){
      if(status) setHTML(status, '<span style=\"color:#b91c1c\"><strong>تعداد سفارش معتبر باید بین ۲ تا ۱۰۰ باشد.</strong></span>');
      return;
    }

    var reasonTa = qs(card,'.fa-change-reason');
    var reason = reasonTa ? reasonTa.value.trim() : '';
    if(reason.length < 2){
      if(status) setHTML(status, '<span style=\"color:#b91c1c\"><strong>علت تغییر الزامی است.</strong></span>');
      return;
    }

    // ✅ شناسه عملیات
    var operationId = genOperationId();

    var options = {reason: reason, operationId: operationId};

    if(mode === 'status'){
      var stSel = qs(card,'.fa-new-status');
      options.newStatus = stSel ? stSel.value : '';
      options.newStatusLabel = (stSel && stSel.options[stSel.selectedIndex]) ? stSel.options[stSel.selectedIndex].text : '';
      var sup = qs(card,'.fa-suppress-email');
      options.suppressEmail = sup ? !!sup.checked : false;
      if(!options.newStatus){
        if(status) setHTML(status, '<span style=\"color:#b91c1c\"><strong>وضعیت جدید را انتخاب کنید.</strong></span>');
        return;
      }
    } else {
      var noteTa = qs(card,'.fa-note-text');
      if(!noteTa || noteTa.value.trim().length < 2){
        if(status) setHTML(status, '<span style=\"color:#b91c1c\"><strong>متن یادداشت الزامی است.</strong></span>');
        return;
      }
    }

    (async function(){
      if(status) setHTML(status, 'در حال فعال سازی قفل ایمن…');

      // ✅ acquire lock همراه با operation_id
      var resLock = await post({ action:'fa_bulk_orders_lock_acquire', _ajax_nonce:'{$nonce}', mode: mode, operation_id: operationId });
      if(!resLock || !resLock.success){
        var msg = (resLock && resLock.data && resLock.data.message) ? resLock.data.message : 'قفل فعال است.';
        var remaining = (resLock && resLock.data && resLock.data.remaining) ? resLock.data.remaining : 300;
        var mins = Math.max(1, Math.ceil(remaining/60));
        if(status) setHTML(status, '<span style=\"color:#b91c1c\"><strong>'+esc(msg)+' لطفاً حدوداً '+mins+' دقیقه دیگر مراجعه کنید.</strong></span>');
        return;
      }

      lockCard(card, true);
      running = true;
      enableBeforeUnload();

      if(runMeta){
        runMeta.style.display = 'flex';
        if(opIdEl) opIdEl.textContent = operationId;
        if(etaEl) etaEl.textContent = '—';
      }

      var stopBtn = qs(card,'.fa-stop');
      var stopped = false;
      var currentController = null;

      function stopNow(){
        stopped = true;
        if(currentController){ try{ currentController.abort(); }catch(_e){} }
        if(stopBtn) stopBtn.disabled = true;
        if(status) setHTML(status, '<strong>توقف انجام شد.</strong>');
        if(etaEl) etaEl.textContent = '—';
      }

      if(stopBtn){
        stopBtn.disabled = false;
        stopBtn.onclick = function(ev){ ev.preventDefault(); stopNow(); };
      }

      var errors = [];
      var successIds = [];
      var total = ids.length;
      var done = 0;

      // ✅ ETA
      var startedAt = Date.now();

      async function runOne(orderId){
        if(stopped) return;

        currentController = new AbortController();

        var data = {
          action:'fa_bulk_orders_do_one',
          _ajax_nonce:'{$nonce}',
          mode: mode,
          operation_id: operationId,
          order_id: orderId,
          change_reason: options.reason
        };

        if(mode === 'status'){
          data.new_status = options.newStatus;
          if(options.suppressEmail) data.suppress_email = '1';
        } else {
          var rt = qs(card,'input[name=\"fa_note_type\"]:checked');
          data.note_type = rt ? rt.value : 'private';
          data.note_text = qs(card,'.fa-note-text').value;
        }

        if(status) setHTML(status, 'در حال پردازش سفارش '+orderId+' … ('+(done+1)+' از '+total+')');

        var t0 = Date.now();
        try{
          var res = await post(data, currentController);
          if(!res || !res.success){
            var msg = (res && res.data && res.data.message) ? res.data.message : 'خطای نامشخص';
            errors.push({order_id: orderId, message: msg});
          } else {
            successIds.push(orderId);
          }
        }catch(err){
          if(!stopped) errors.push({order_id: orderId, message: 'خطای ارتباط یا قطع درخواست'});
        }finally{
          currentController = null;
        }

        done++;
        if(bar) bar.style.width = Math.round((done/total)*100) + '%';

        // ✅ ETA update
        if(done >= 1 && etaEl && !stopped){
          var elapsed = Date.now() - startedAt;
          var avg = elapsed / done;
          var remaining = (total - done) * avg;

          // تخمین مکث‌های batch هم اضافه میشه
          var remainingBatches = Math.floor((total - done) / BATCH_SIZE);
          remaining += (remainingBatches * BATCH_PAUSE_MS);

          etaEl.textContent = formatETA(remaining);
        }

        await sleep(BETWEEN_REQUESTS_MS);
      }

      for(var i=0;i<ids.length;i++){
        if(stopped) break;

        await runOne(ids[i]);

        var idx = i + 1;
        if(!stopped && idx % BATCH_SIZE === 0 && idx < ids.length){
          if(status) setHTML(status, 'مکث کوتاه برای کاهش فشار روی سرور…');
          await sleep(BATCH_PAUSE_MS);
        }
      }

      running = false;
      disableBeforeUnload();
      await releaseLock();

      // پیام نهایی سه حالته
      if(!stopped){
        if(successIds.length === total){
          setHTML(status, '<span class=\"fa-badge fa-ok\">همه سفارش ها با موفقیت اعمال شدند.</span>');
        } else if(successIds.length > 0 && successIds.length < total){
          setHTML(status, '<span class=\"fa-badge fa-warn\">بخشی از سفارش ها انجام شد و بخشی با خطا مواجه شد.</span>');
        } else {
          setHTML(status, '<span class=\"fa-badge fa-no\">هیچ کدام از سفارش ها اعمال نشدند و همه با خطا مواجه شدند.</span>');
        }

        if(errors.length){
          var div = document.createElement('div');
          div.className = 'fa-errors';
          div.innerHTML = '<h3>سفارش های دارای خطا</h3><ul></ul>';
          var ul = div.querySelector('ul');
          errors.forEach(function(er){
            var li = document.createElement('li');
            li.textContent = 'سفارش ' + er.order_id + ' — ' + er.message;
            ul.appendChild(li);
          });
          card.appendChild(div);
        }
      }

      var report = buildReport(mode, total, successIds, errors, options);
      if(copyWrap) copyWrap.style.display = 'flex';
      if(copyBox){ copyBox.style.display='block'; copyBox.textContent = report; }
      if(copyBtn) copyBtn.disabled = false;

      if(copyBtn){
        copyBtn.onclick = async function(){
          var ok = await copyText(report);
          copyBtn.textContent = ok ? 'گزارش کپی شد' : 'کپی نشد';
          setTimeout(function(){ copyBtn.textContent = 'کپی گزارش'; }, 1400);
        };
      }

      // آزادسازی فرم
      qsa(card,'textarea,input,select').forEach(function(x){ x.disabled = false; });
      var cb = qs(card,'.fa-confirm-cb');
      if(cb){ cb.checked = false; }

      previewApproved = false;
      previewFingerprint = '';

      var startBtn = qs(card,'.fa-start');
      if(startBtn) startBtn.disabled = true;
      var previewBtn = qs(card,'.fa-preview');
      if(previewBtn) previewBtn.disabled = true;
      if(stopBtn) stopBtn.disabled = true;

      if(etaEl) etaEl.textContent = '—';

      refreshSummary(card);
      refreshButtons(card);
    })();

  }, true);

  document.querySelectorAll('.fa-card').forEach(function(card){
    refreshSummary(card);
    refreshButtons(card);
  });

})();</script>";
	}
}

FA_Bulk_Order_Editor::init();
