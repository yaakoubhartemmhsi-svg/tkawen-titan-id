<?php
/**
 * Plugin Name: Sovereign Digital Identity & Wallet
 * Description: A comprehensive digital identity and wallet plugin with a cinematic mobile-first UI, Google SSO, and QR code verification.
 * Version: 1.0.0
 * Author: Open Source Developer
 */

if (!defined('ABSPATH')) exit;

class WP_Digital_Identity {

    private static $instance = null;
    private $db;
    // قم بوضع Google Client ID الخاص بك هنا
    private $google_client_id = 'YOUR_GOOGLE_CLIENT_ID_HERE';

    public static function get_instance() {
        if (self::$instance == null) self::$instance = new self();
        return self::$instance;
    }

    public function __construct() {
        global $wpdb;
        $this->db = $wpdb;

        register_activation_hook(__FILE__, [$this, 'install_schema']);
        add_action('wp_enqueue_scripts', [$this, 'load_assets']);
        add_shortcode('digital_id_app', [$this, 'render_app']);

        add_action('wp_ajax_diw_ajax_auth', [$this, 'ajax_auth_handler']);
        add_action('wp_ajax_nopriv_diw_ajax_auth', [$this, 'ajax_auth_handler']);
    }

    public function install_schema() {
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        $charset = $this->db->get_charset_collate();

        $sql1 = "CREATE TABLE {$this->db->prefix}diw_wallets (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned DEFAULT 0,
            did VARCHAR(255) NOT NULL,
            student_email VARCHAR(100) NOT NULL,
            status VARCHAR(20) DEFAULT 'active',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id), UNIQUE KEY did (did)
        ) $charset;";

        $sql2 = "CREATE TABLE {$this->db->prefix}diw_certs (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            uuid varchar(64) UNIQUE,
            student_email varchar(100),
            course_name varchar(255) NOT NULL,
            issue_date date NOT NULL,
            status varchar(20) DEFAULT 'valid',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $charset;";
        
        dbDelta($sql1);
        dbDelta($sql2);
    }

    public function load_assets() {
        wp_enqueue_script('jquery');
        wp_enqueue_script('html5-qrcode', 'https://unpkg.com/html5-qrcode', [], '2.3.8', true);
        wp_enqueue_script('qrcode-js', 'https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js', [], '1.0.0', true);
        wp_enqueue_script('html2canvas', 'https://html2canvas.hertzen.com/dist/html2canvas.min.js', [], '1.4.1', true);
        wp_enqueue_script('google-client', 'https://accounts.google.com/gsi/client', [], null, true);

        ?>
        <style>
            @import url('https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;900&family=Outfit:wght@400;700;900&family=Playfair+Display:wght@700&display=swap');
            @import url('https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css');

            :root { 
                --diw-dark-blue: #000428; 
                --diw-main-blue: #0B05D8; 
                --diw-light-blue: #eff6ff;
                --diw-gold: #FFAE00; 
                --diw-white: #ffffff; 
            }
            * { box-sizing: border-box; }
            body { font-family: 'Cairo', sans-serif; background: #f0f4f8; margin: 0; }

            .diw-app-wrap { min-height: 100vh; display: flex; justify-content: center; align-items: center; padding: 20px; direction: rtl; }

            .diw-sovereign-card {
                width: 420px; height: 265px; 
                background: linear-gradient(135deg, var(--diw-dark-blue) 0%, var(--diw-main-blue) 100%);
                border-radius: 18px; position: relative; overflow: hidden;
                box-shadow: 0 25px 60px rgba(11, 5, 216, 0.4);
                border: 1px solid rgba(255,255,255,0.15); color: #fff; font-family: 'Outfit', sans-serif;
                margin: 0 auto; transition: 0.3s;
                flex-shrink: 0;
            }
            .diw-sovereign-card:hover { transform: translateY(-5px) scale(1.02); }

            .diw-card-top { position: absolute; top: 20px; left: 25px; right: 25px; display: flex; justify-content: space-between; align-items: center; }
            .diw-card-title { color: var(--diw-gold); font-weight: 900; font-size: 18px; letter-spacing: 1px; }
            .diw-card-subtitle { font-size: 9px; font-weight: 600; opacity: 0.8; text-transform: uppercase; }
            
            .diw-card-qr {
                position: absolute; top: 70px; left: 25px; background: #fff; padding: 4px; border-radius: 8px;
                width: 100px; height: 100px; display: flex; justify-content: center; align-items: center;
                box-shadow: 0 5px 15px rgba(0,0,0,0.3);
            }
            .diw-shield-icon { position: absolute; width: 22px; height: 22px; background: #fff; border-radius: 50%; display: flex; justify-content: center; align-items: center; z-index: 10; color: var(--diw-main-blue); font-size: 12px; }
            
            .diw-card-data { position: absolute; top: 70px; right: 25px; text-align: right; width: 260px; }
            .diw-lbl { font-size: 7px; color: var(--diw-gold); text-transform: uppercase; letter-spacing: 1px; margin-bottom: 2px; }
            .diw-val-name { font-size: 19px; font-weight: 800; text-transform: uppercase; margin-bottom: 12px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
            .diw-did-box { 
                background: rgba(255,255,255,0.1); padding: 6px 8px; border-radius: 4px; 
                font-family: monospace; font-size: 9px; color: #fff; margin-bottom: 12px; 
                display: block; word-break: break-all; line-height: 1.2; text-align: center; border: 1px solid rgba(255,255,255,0.2);
            }
            
            .diw-card-status-left { position: absolute; bottom: 25px; left: 25px; text-align: left; }
            .diw-status-text { font-size: 13px; font-weight: 700; color: #fff; display: flex; align-items: center; gap: 5px; }
            .diw-status-dot { width: 8px; height: 8px; background: #00ff88; border-radius: 50%; box-shadow: 0 0 10px #00ff88; }
            .diw-issuer-right { position: absolute; bottom: 25px; right: 25px; font-size: 8px; opacity: 0.6; letter-spacing: 1px; text-transform: uppercase; }

            .diw-nexus-container { display: flex; width: 1000px; min-height: 600px; background: #fff; border-radius: 24px; overflow: hidden; box-shadow: 0 40px 90px rgba(11, 5, 216, 0.2); }
            .diw-nexus-visual { flex: 1; background: var(--diw-dark-blue); display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 40px; background-image: linear-gradient(160deg, var(--diw-dark-blue) 0%, var(--diw-main-blue) 100%); position: relative; }
            .diw-nexus-forms { flex: 1.2; padding: 50px; display: flex; flex-direction: column; justify-content: center; background: #fff; }
            
            @media (max-width: 500px) {
                .diw-nexus-container { 
                    flex-direction: column; 
                    width: 100% !important; 
                    border-radius: 0;
                    box-shadow: none;
                }
                
                .diw-nexus-visual { 
                    display: flex !important; 
                    height: 300px !important; 
                    min-height: auto !important;
                    padding: 20px !important;
                    order: -1; 
                    border-radius: 0 0 40px 40px; 
                    z-index: 2; 
                    box-shadow: 0 10px 40px rgba(0,0,0,0.3);
                }

                .diw-sovereign-card {
                    width: 420px !important;
                    height: 265px !important;
                    min-width: 420px !important;
                    transform: scale(0.68); 
                    transform-origin: center center;
                    animation: diwBreath 5s ease-in-out infinite; 
                    margin: 0 !important;
                    box-shadow: 0 20px 50px rgba(0,0,0,0.5) !important;
                }

                .diw-nexus-forms { 
                    padding: 40px 20px !important; 
                    margin-top: -30px; 
                    padding-top: 50px !important;
                    border-radius: 30px 30px 0 0;
                    z-index: 1;
                }

                .diw-visual-info { display: none; }
            }

            @keyframes diwBreath {
                0% { transform: scale(0.68) translateY(0px); }
                50% { transform: scale(0.68) translateY(-10px); } 
                100% { transform: scale(0.68) translateY(0px); }
            }

            .diw-form-title { color: var(--diw-main-blue); margin-bottom: 5px; font-size: 28px; font-weight: 900; }
            .diw-form-desc { color: #64748b; font-size: 14px; margin-bottom: 30px; font-weight: 600; }

            .diw-auth-tabs { display: flex; background: #eef2ff; padding: 6px; border-radius: 12px; margin-bottom: 30px; }
            .diw-auth-tab { flex: 1; padding: 12px; text-align: center; cursor: pointer; font-weight: 700; font-size: 13px; color: var(--diw-main-blue); opacity: 0.6; border-radius: 8px; transition:0.3s; }
            .diw-auth-tab:hover { opacity: 1; background: rgba(11,5,216,0.05); }
            .diw-auth-tab.active { background: var(--diw-main-blue); color: #fff; opacity: 1; box-shadow: 0 4px 15px rgba(11, 5, 216, 0.3); }
            
            .diw-inp { width: 100%; padding: 16px; border: 2px solid #e0e7ff; border-radius: 12px; margin-bottom: 15px; font-family: 'Cairo'; font-weight: 600; text-align: right; background: #f8fafc; color: var(--diw-dark-blue); transition: 0.3s; }
            .diw-inp:focus { border-color: var(--diw-main-blue); outline: none; background: #fff; box-shadow: 0 0 0 4px rgba(11, 5, 216, 0.1); }
            
            .diw-btn { width: 100%; padding: 16px; background: linear-gradient(90deg, var(--diw-main-blue), var(--diw-dark-blue)); color: #fff; border: none; border-radius: 12px; font-weight: bold; cursor: pointer; transition: 0.3s; font-size: 16px; box-shadow: 0 10px 20px rgba(11, 5, 216, 0.2); }
            .diw-btn:hover { transform: translateY(-2px); box-shadow: 0 15px 30px rgba(11, 5, 216, 0.3); }
            
            .diw-link { color: var(--diw-main-blue); font-size: 13px; font-weight: 700; text-decoration: none; cursor: pointer; display: block; margin-top: 15px; text-align: center; transition: 0.3s; }
            
            .diw-google-container { width: 100%; margin-bottom: 25px; display: flex; justify-content: center; }
            .diw-divider { display: flex; align-items: center; text-align: center; margin: 20px 0; color: #94a3b8; font-size: 12px; font-weight: 600; }
            .diw-divider::before, .diw-divider::after { content: ''; flex: 1; border-bottom: 1px solid #e2e8f0; }
            .diw-divider::before { margin-left: 10px; } .diw-divider::after { margin-right: 10px; }

            #scanner-overlay, #minting-overlay { position: fixed; inset: 0; background: rgba(0,4,40,0.95); z-index: 10000; display: none; flex-direction: column; align-items: center; justify-content: center; color: #fff; backdrop-filter: blur(10px); }
            .diw-scan-box { width: 300px; height: 300px; border: 3px solid var(--diw-gold); border-radius: 20px; position: relative; overflow: hidden; box-shadow: 0 0 50px var(--diw-main-blue); }
            .diw-laser { position: absolute; width: 100%; height: 4px; background: var(--diw-main-blue); animation: scan 1.5s infinite; box-shadow: 0 0 20px var(--diw-main-blue); }
            @keyframes scan { 0% {top:0} 50% {top:100%} 100% {top:0} }
            .diw-loader-ring { width: 80px; height: 80px; border: 4px solid rgba(255,255,255,0.1); border-top: 4px solid var(--diw-gold); border-radius: 50%; animation: spin 1s linear infinite; margin-bottom: 20px; }
            @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
            
            .diw-view { display: none; animation: fadeIn 0.4s; }
            .diw-view.active { display: block; }
            @keyframes fadeIn { from { opacity:0; transform:translateY(10px); } to { opacity:1; transform:translateY(0); } }
            .diw-msg-box { background: #e0f2fe; color: #075985; padding: 12px; border-radius: 8px; font-size: 13px; margin-bottom: 20px; border-right: 4px solid var(--diw-main-blue); display: flex; align-items: center; gap: 10px; }
        </style>
        <?php
    }

    public function render_app() {
        nocache_headers(); 
        if (is_user_logged_in()) {
            $user = wp_get_current_user();
            $exists = $this->db->get_var($this->db->prepare("SELECT id FROM {$this->db->prefix}diw_wallets WHERE user_id = %d", $user->ID));
            if (!$exists) {
                $did = 'did:org:' . bin2hex(random_bytes(16)); 
                $this->db->insert("{$this->db->prefix}diw_wallets", ['user_id' => $user->ID, 'did' => $did, 'student_email' => $user->user_email]);
            }
        }

        ob_start();
        ?>
        <div class="diw-app-wrap">
            
            <?php if (!is_user_logged_in()): ?>
                <div id="g_id_onload"
                     data-client_id="<?php echo esc_attr($this->google_client_id); ?>"
                     data-context="signin"
                     data-ux_mode="popup"
                     data-callback="diwHandleGoogle"
                     data-auto_prompt="false">
                </div>

                <div class="diw-nexus-container" id="auth-ui">
                    <div class="diw-nexus-visual">
                        <div class="diw-visual-info" style="margin-bottom:30px; text-align:center;">
                            <h2 style="margin:0; color:#fff; font-size:32px;">DIGITAL ID</h2>
                            <p style="margin:5px 0 0; font-size:14px; opacity:0.8; color:#a5b4fc;">بوابة الهوية الرقمية الموحدة</p>
                        </div>
                        <div class="diw-sovereign-card">
                            <div class="diw-card-top"><div class="diw-card-title">DIGITAL ID</div><div class="diw-card-subtitle">Digital Identity</div></div>
                            <div class="diw-card-qr"><div class="diw-shield-icon"><i class="fa-solid fa-lock"></i></div><img src="https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=PREVIEW" style="width:100%;"></div>
                            <div class="diw-card-data">
                                <div class="diw-lbl">IDENTITY HOLDER</div>
                                <div class="diw-val-name" id="preview-name">YOUR NAME</div>
                                <div class="diw-lbl">DIGITAL IDENTIFIER (DID)</div>
                                <div class="diw-did-box">did:org:pending...</div>
                            </div>
                            <div class="diw-card-status-left"><div class="diw-status-text">Verified <span class="diw-status-dot"></span></div></div>
                            <div class="diw-issuer-right">Issued by: Organization</div>
                        </div>
                    </div>

                    <div class="diw-nexus-forms">
                        <div class="diw-form-title">مرحباً بك مجدداً 👋</div>
                        <div class="diw-form-desc">قم بتسجيل الدخول للوصول إلى محفظتك الرقمية</div>

                        <div class="diw-auth-tabs">
                            <div class="diw-auth-tab active" onclick="diwUI.tab('creds')">كلمة السر</div>
                            <div class="diw-auth-tab" onclick="diwUI.tab('did')">هوية (DID)</div>
                            <div class="diw-auth-tab" onclick="diwUI.tab('scan')">ماسح QR</div>
                            <div class="diw-auth-tab" onclick="diwUI.tab('register')">حساب جديد</div>
                        </div>

                        <div id="view-creds" class="diw-view active">
                            <div class="diw-google-container">
                                <div class="g_id_signin" data-type="standard" data-shape="rectangular" data-theme="outline" data-text="signin_with" data-size="large" data-logo_alignment="left" data-width="320"></div>
                            </div>
                            <div class="diw-divider">أو تابع باستخدام البريد</div>
                            <input type="text" id="log_user" class="diw-inp" placeholder="اسم المستخدم أو البريد">
                            <input type="password" id="log_pass" class="diw-inp" placeholder="كلمة المرور">
                            <button onclick="diwAuth.login('creds')" class="diw-btn">تسجيل الدخول</button>
                            <span class="diw-link" onclick="diwUI.tab('forgot')">هل نسيت كلمة المرور؟</span>
                        </div>

                        <div id="view-did" class="diw-view">
                            <input type="text" id="log_did" class="diw-inp" placeholder="أدخل معرف الهوية الرقمية (DID)">
                            <button onclick="diwAuth.login('did')" class="diw-btn">دخول آمن</button>
                        </div>

                        <div id="view-scan" class="diw-view" style="text-align:center;">
                            <div style="background:#f0f9ff; padding:30px; border-radius:20px; border:2px dashed var(--diw-main-blue); margin-bottom:20px;">
                                <i class="fa-solid fa-qrcode" style="font-size:50px; color:var(--diw-main-blue);"></i>
                            </div>
                            <button onclick="diwAuth.startScan()" class="diw-btn">فتح الكاميرا للمسح</button>
                        </div>

                        <div id="view-register" class="diw-view">
                            <div class="diw-msg-box"><i class="fa-solid fa-shield-alt"></i> <span>سنرسل رمز تحقق (OTP) لبريدك.</span></div>
                            <div id="reg-step-1">
                                <input type="text" id="reg_name" class="diw-inp" placeholder="الاسم الكامل" onkeyup="document.getElementById('preview-name').innerText=this.value||'YOUR NAME'">
                                <input type="text" id="reg_user" class="diw-inp" placeholder="اسم المستخدم">
                                <input type="email" id="reg_email" class="diw-inp" placeholder="البريد الإلكتروني">
                                <input type="password" id="reg_pass" class="diw-inp" placeholder="كلمة المرور">
                                <button onclick="diwAuth.registerStart()" class="diw-btn">إرسال رمز التحقق</button>
                            </div>
                            <div id="reg-step-2" style="display:none;">
                                <p style="text-align:center; font-weight:bold; color:var(--diw-main-blue);">أدخل الرمز المرسل:</p>
                                <input type="text" id="reg_otp" class="diw-inp" placeholder="X X X X X" style="text-align:center; font-size:20px;">
                                <button onclick="diwAuth.registerConfirm()" class="diw-btn">تأكيد</button>
                            </div>
                        </div>

                        <div id="view-forgot" class="diw-view">
                            <h4 style="color:var(--diw-main-blue); margin-top:0;">استعادة الحساب</h4>
                            <div id="reset-step-1">
                                <input type="email" id="reset_email" class="diw-inp" placeholder="البريد الإلكتروني">
                                <button onclick="diwAuth.resetStart()" class="diw-btn">إرسال الرمز</button>
                                <span class="diw-link" onclick="diwUI.tab('creds')">تراجـع</span>
                            </div>
                            <div id="reset-step-2" style="display:none;">
                                <input type="text" id="reset_otp" class="diw-inp" placeholder="رمز التحقق (OTP)">
                                <input type="password" id="reset_new_pass" class="diw-inp" placeholder="كلمة المرور الجديدة">
                                <button onclick="diwAuth.resetConfirm()" class="diw-btn">حفظ</button>
                            </div>
                        </div>
                    </div>
                </div>

                <div id="minting-overlay"><div class="diw-loader-ring"></div><div style="font-family:'Outfit'; font-size:22px; color:var(--diw-gold);">جاري تشفير الهوية...</div></div>
                <div id="scanner-overlay"><div class="diw-scan-box"><div class="diw-laser"></div><div id="qr-reader" style="width:100%; height:100%;"></div></div><button onclick="location.reload()" style="margin-top:30px; color:#fff; background:transparent; border:1px solid #fff; padding:10px 30px; border-radius:30px;">إغلاق</button></div>

            <?php else: 
                // === DASHBOARD ===
                $user = wp_get_current_user();
                $wallet = $this->db->get_row($this->db->prepare("SELECT * FROM {$this->db->prefix}diw_wallets WHERE user_id = %d", $user->ID));
                $certs = $this->db->get_results($this->db->prepare("SELECT * FROM {$this->db->prefix}diw_certs WHERE student_email = %s", $user->user_email));
            ?>
                <div style="width:100%; max-width:1100px; background:#fff; border-radius:24px; padding:40px; box-shadow:0 20px 60px rgba(11, 5, 216, 0.1);">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:40px;">
                        <h2 style="margin:0; color:var(--diw-main-blue);">المحفظة الرقمية</h2>
                        <a href="<?php echo wp_logout_url(home_url()); ?>" style="background:#fee2e2; color:#ef4444; padding:8px 20px; border-radius:20px; text-decoration:none; font-weight:bold;"><i class="fa-solid fa-power-off"></i> خروج</a>
                    </div>
                    <div style="display:flex; flex-wrap:wrap; gap:40px; justify-content:center;">
                        <div class="diw-sovereign-card" id="my-card">
                            <div class="diw-card-top"><div class="diw-card-title">DIGITAL ID</div><div class="diw-card-subtitle">Digital Identity</div></div>
                            <div class="diw-card-qr"><div class="diw-shield-icon"><i class="fa-solid fa-shield-halved"></i></div><div id="wallet-qr"></div></div>
                            <div class="diw-card-data"><div class="diw-lbl">IDENTITY HOLDER</div><div class="diw-val-name"><?php echo esc_html($user->display_name); ?></div><div class="diw-lbl">DIGITAL IDENTIFIER (DID)</div><div class="diw-did-box"><?php echo esc_html($wallet->did); ?></div></div>
                            <div class="diw-card-status-left"><div class="diw-status-text">Verified <span class="diw-status-dot"></span></div></div>
                            <div class="diw-issuer-right">Issued by: Organization</div>
                        </div>
                        <div style="flex:1; min-width:350px;">
                            <div style="display:flex; gap:10px; margin-bottom:25px;">
                                <button onclick="diwAuth.downloadCard()" class="diw-btn" style="background:var(--diw-dark-blue);">تحميل البطاقة <i class="fa-solid fa-download"></i></button>
                                <button onclick="diwAuth.printTranscript()" class="diw-btn">طباعة السجل <i class="fa-solid fa-print"></i></button>
                            </div>
                            <h4 style="color:var(--diw-main-blue); border-bottom:2px solid #eef2ff; padding-bottom:10px;">السجل الأكاديمي</h4>
                            <script type="text/template" id="cert-data-template"><?php if($certs): foreach($certs as $c): ?><tr><td><?php echo esc_html($c->course_name); ?></td><td style="text-align:center;"><?php echo esc_html($c->issue_date); ?></td><td style="text-align:center;"><span class="badge">Pass</span></td><td style="text-align:center; font-family:monospace;"><?php echo esc_html(substr($c->uuid, 0, 15)); ?>...</td></tr><?php endforeach; else: ?><tr><td colspan="4" style="text-align:center;">No courses found.</td></tr><?php endif; ?></script>
                            <?php if($certs): foreach($certs as $c): ?><div style="padding:15px; background:#f8fafc; border-radius:10px; margin-bottom:10px; display:flex; justify-content:space-between;"><span style="font-weight:600;"><?php echo esc_html($c->course_name); ?></span><span style="background:#dcfce7; color:#166534; padding:3px 10px; border-radius:20px; font-size:12px; font-weight:bold;">Verified</span></div><?php endforeach; else: echo '<p style="color:#999;">لا توجد سجلات.</p>'; endif; ?>
                        </div>
                    </div>
                    <script>setTimeout(() => { new QRCode(document.getElementById("wallet-qr"), { text: "<?php echo esc_js($wallet->did); ?>", width: 92, height: 92 }); }, 500);</script>
                </div>
            <?php endif; ?>
        </div>

        <script>
        window.diwHandleGoogle = function(response) {
            jQuery.post('<?php echo admin_url('admin-ajax.php'); ?>', {
                action: 'diw_ajax_auth', mode: 'google_login', credential: response.credential
            }, function(res) {
                if(res.success) window.location.reload(); 
                else alert(res.data.msg);
            });
        };

        const diwUI = {
            tab: (id) => {
                document.querySelectorAll('.diw-auth-tab').forEach(t => t.classList.remove('active'));
                if(document.querySelector(`.diw-auth-tab[onclick="diwUI.tab('${id}')"]`))
                    document.querySelector(`.diw-auth-tab[onclick="diwUI.tab('${id}')"]`).classList.add('active');
                document.querySelectorAll('.diw-view').forEach(v => v.classList.remove('active'));
                document.getElementById('view-'+id).classList.add('active');
            },
            showMinting: () => { document.getElementById('auth-ui').style.display='none'; document.getElementById('minting-overlay').style.display='flex'; setTimeout(()=>location.reload(),3000); }
        };

        const diwAuth = {
            login: (mode) => {
                let d = { action: 'diw_ajax_auth', mode: 'login_creds' };
                if(mode === 'creds') { d.user = jQuery('#log_user').val(); d.pass = jQuery('#log_pass').val(); } 
                else { d.mode = 'login_did'; d.did = jQuery('#log_did').val(); }
                jQuery.post('<?php echo admin_url('admin-ajax.php'); ?>', d, (res) => { 
                    if(res.success) window.location.reload(); 
                    else alert(res.data.msg); 
                });
            },
            tempRegData: {},
            registerStart: () => {
                diwAuth.tempRegData = { action: 'diw_ajax_auth', mode: 'register_start', name: jQuery('#reg_name').val(), user: jQuery('#reg_user').val(), email: jQuery('#reg_email').val(), pass: jQuery('#reg_pass').val() };
                if(!diwAuth.tempRegData.email) return alert('البيانات ناقصة');
                jQuery.post('<?php echo admin_url('admin-ajax.php'); ?>', diwAuth.tempRegData, (res) => { if(res.success) { jQuery('#reg-step-1').slideUp(); jQuery('#reg-step-2').slideDown(); } else alert(res.data.msg); });
            },
            registerConfirm: () => {
                let d = { ...diwAuth.tempRegData, mode: 'register_confirm', otp: jQuery('#reg_otp').val() };
                jQuery.post('<?php echo admin_url('admin-ajax.php'); ?>', d, (res) => { if(res.success) diwUI.showMinting(); else alert(res.data.msg); });
            },
            resetStart: () => {
                let email = jQuery('#reset_email').val();
                if(!email) return alert('أدخل البريد');
                diwAuth.tempResetEmail = email;
                jQuery.post('<?php echo admin_url('admin-ajax.php'); ?>', {action:'diw_ajax_auth', mode:'reset_start', email:email}, (res)=>{ if(res.success) { jQuery('#reset-step-1').slideUp(); jQuery('#reset-step-2').slideDown(); } else alert(res.data.msg); });
            },
            resetConfirm: () => {
                jQuery.post('<?php echo admin_url('admin-ajax.php'); ?>', { action:'diw_ajax_auth', mode:'reset_confirm', email:diwAuth.tempResetEmail, otp:jQuery('#reset_otp').val(), pass:jQuery('#reset_new_pass').val() }, (res)=>{ if(res.success) { alert('تم!'); diwUI.tab('creds'); } else alert(res.data.msg); });
            },
            startScan: () => {
                document.getElementById('scanner-overlay').style.display='flex';
                const s = new Html5Qrcode("qr-reader");
                s.start({facingMode:"environment"}, {fps:20,qrbox:250}, (did)=>{ s.stop(); jQuery.post('<?php echo admin_url('admin-ajax.php'); ?>', {action:'diw_ajax_auth',mode:'login_scan',did:did}, (res)=>{ if(res.success) window.location.reload(); else alert('خطأ في الهوية'); }); });
            },
            downloadCard: () => { html2canvas(document.getElementById('my-card'),{scale:3}).then(c=>{ let a=document.createElement('a'); a.download='Digital_ID.png'; a.href=c.toDataURL(); a.click(); }); },
            printTranscript: () => {
                const name = "<?php echo isset($user) ? esc_js($user->display_name) : ''; ?>";
                const did = "<?php echo isset($wallet) ? esc_js($wallet->did) : ''; ?>";
                const rows = document.getElementById('cert-data-template').innerHTML;
                const ref = 'ID-' + Math.floor(Math.random()*900000);
                const date = new Date().toISOString().slice(0,10);
                var w = window.open('','_blank');
                w.document.write(`<html><head><title>Transcript</title><style>@import url('https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700&family=Playfair+Display:wght@700&display=swap');body{font-family:'Times New Roman',serif;padding:40px;max-width:850px;margin:auto;}.header{text-align:center;border-bottom:3px solid #000428;padding-bottom:20px;margin-bottom:30px;}.republic{font-size:14px;font-weight:bold;text-transform:uppercase;letter-spacing:2px;}.title{font-family:'Playfair Display',serif;font-size:36px;font-weight:bold;margin:15px 0;text-transform:uppercase;color:#000428;}.info-box{width:100%;margin-bottom:30px;border:1px solid #ccc;padding:15px;background:#f9f9f9;display:grid;grid-template-columns:2fr 1fr;gap:15px;font-family:'Cairo';position:relative;}.label{font-size:10px;font-weight:bold;text-transform:uppercase;color:#777;display:block;}.value{font-size:14px;font-weight:700;}.trans-qr{position:absolute;top:10px;right:10px;background:#fff;padding:5px;border:1px solid #ddd;}table{width:100%;border-collapse:collapse;margin-bottom:40px;font-family:'Cairo';}th{background-color:#000428!important;color:#fff!important;border:1px solid #000;padding:12px;text-align:left;font-size:12px;font-weight:bold;text-transform:uppercase;-webkit-print-color-adjust:exact;}td{border:1px solid #000;padding:10px;font-size:13px;font-weight:600;}tr:nth-child(even){background-color:#f2f2f2;-webkit-print-color-adjust:exact;}.badge{background:#dcfce7;color:#166534;padding:3px 8px;border-radius:4px;font-size:11px;border:1px solid #86efac;text-transform:uppercase;-webkit-print-color-adjust:exact;}.legal{font-size:10px;text-align:justify;padding:15px;border:1px solid #000;margin-top:30px;}.digital-seal-box{margin-top:30px;display:flex;align-items:center;justify-content:space-between;border-top:1px solid #eee;padding-top:20px;}.seal-img{width:100px;height:100px;border:3px double #000428;border-radius:50%;display:flex;align-items:center;justify-content:center;color:#000428;font-weight:bold;font-family:'Courier New';font-size:10px;text-align:center;transform:rotate(-15deg);opacity:0.8;}.signature{font-family:'Courier New',monospace;font-size:10px;color:#555;width:70%;word-break:break-all;}.footer{text-align:center;font-size:10px;margin-top:20px;font-weight:bold;border-top:1px solid #eee;padding-top:10px;}</style></head><body><div class="header"><div class="republic">Issuing Organization Name</div><div class="title">Official Academic Transcript</div><div>Record of Achievement | السجل الأكاديمي</div></div><div class="info-box"><div class="info-col"><div class="info-row"><span class="label">Holder Name:</span><span class="value">${name}</span></div><div class="info-row"><span class="label">Digital Identity:</span><span class="value" style="font-family:monospace">${did}</span></div></div><div class="info-col"><div class="info-row"><span class="label">Reference ID:</span><span class="value">${ref}</span></div><div class="info-row"><span class="label">Date of Issue:</span><span class="value">${date}</span></div></div><div id="trans-qr-code" class="trans-qr"></div></div><table><thead><tr><th style="width:40%">Record Name</th><th style="width:20%; text-align:center;">Date</th><th style="width:15%; text-align:center;">Status</th><th style="width:25%; text-align:center;">Verification ID</th></tr></thead><tbody>${rows}</tbody></table><div class="legal"><strong>Legal Verification Notice:</strong><br>This document is officially recorded in our central verification system. Its validity is strictly contingent upon digital verification via the QR code or reference ID on the official platform.</div><div class="digital-seal-box"><div class="seal-img">OFFICIAL<br>DIGITAL<br>SEAL</div><div class="signature"><strong>Digital Signature Hash:</strong><br>8f4b2e6d9a1c3f5e8b0d7a4c2e9f1a6b3d5c8e0f2a4b6d8e1c3f5a7b9d0e2c4f</div></div><div class="footer">Website: www.example.com | Email: contact@example.com</div><script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"><\/script><script>new QRCode(document.getElementById("trans-qr-code"),{text:"${did}",width:80,height:80}); setTimeout(()=>window.print(),1000);<\/script></body></html>`); w.document.close();
            }
        };
        </script>
        <?php
        return ob_get_clean();
    }

    public function ajax_auth_handler() {
        $mode = $_POST['mode'];
        
        if ($mode === 'google_login') {
            $credential = sanitize_text_field($_POST['credential']);
            $response = wp_remote_get('https://oauth2.googleapis.com/tokeninfo?id_token=' . $credential);
            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);

            if(isset($data['email'])) {
                $email = sanitize_email($data['email']);
                $name = sanitize_text_field($data['name']);
                
                $user = get_user_by('email', $email);
                if(!$user) {
                    $password = wp_generate_password(12, true);
                    $uid = wp_create_user($email, $password, $email);
                    wp_update_user(['ID'=>$uid, 'display_name'=>$name]);
                    $did = 'did:org:' . bin2hex(random_bytes(16));
                    $this->db->insert("{$this->db->prefix}diw_wallets", ['user_id'=>$uid, 'did'=>$did, 'student_email'=>$email]);
                    
                    clean_user_cache($uid);
                    wp_clear_auth_cookie();
                    wp_set_current_user($uid);
                    wp_set_auth_cookie($uid, true);
                    wp_send_json_success(['msg'=>'تم إنشاء الحساب بنجاح']);
                } else {
                    clean_user_cache($user->ID);
                    wp_clear_auth_cookie();
                    wp_set_current_user($user->ID);
                    wp_set_auth_cookie($user->ID, true);
                    wp_send_json_success(['msg'=>'تم تسجيل الدخول']);
                }
            } else {
                wp_send_json_error(['msg'=>'فشل التحقق من Google']);
            }
        }
        
        elseif ($mode === 'login_creds') {
            $u = wp_signon(['user_login'=>$_POST['user'], 'user_password'=>$_POST['pass'], 'remember'=>true], false);
            if (is_wp_error($u)) wp_send_json_error(['msg'=>'بيانات خاطئة']); else wp_send_json_success();
        } 
        elseif ($mode === 'login_did' || $mode === 'login_scan') {
            $did = sanitize_text_field($_POST['did']);
            $row = $this->db->get_row($this->db->prepare("SELECT user_id FROM {$this->db->prefix}diw_wallets WHERE did=%s", $did));
            if ($row) { 
                wp_set_current_user($row->user_id); wp_set_auth_cookie($row->user_id, true); wp_send_json_success(); 
            } 
            else wp_send_json_error(['msg'=>'الهوية غير موجودة']);
        }
        elseif ($mode === 'register_start') {
            $email = sanitize_email($_POST['email']);
            if (email_exists($email) || username_exists($_POST['user'])) wp_send_json_error(['msg'=>'المستخدم موجود بالفعل']);
            $otp = rand(10000, 99999);
            update_option('diw_temp_otp_'.md5($email), $otp, false);
            wp_mail($email, 'رمز التحقق', "رمزك هو: $otp");
            wp_send_json_success();
        }
        elseif ($mode === 'register_confirm') {
            $email = sanitize_email($_POST['email']);
            $saved = get_option('diw_temp_otp_'.md5($email));
            if ($saved && $saved == $_POST['otp']) {
                $uid = wp_create_user($_POST['user'], $_POST['pass'], $email);
                if (is_wp_error($uid)) wp_send_json_error(['msg'=>$uid->get_error_message()]);
                wp_update_user(['ID'=>$uid, 'display_name'=>sanitize_text_field($_POST['name'])]);
                $did = 'did:org:' . bin2hex(random_bytes(16));
                $this->db->insert("{$this->db->prefix}diw_wallets", ['user_id'=>$uid, 'did'=>$did, 'student_email'=>$email]);
                wp_signon(['user_login'=>$_POST['user'], 'user_password'=>$_POST['pass']], false);
                delete_option('diw_temp_otp_'.md5($email));
                wp_send_json_success();
            } else wp_send_json_error(['msg'=>'رمز خاطئ']);
        }
        elseif ($mode === 'reset_start') {
            $email = sanitize_email($_POST['email']);
            $user = get_user_by('email', $email);
            if (!$user) wp_send_json_error(['msg'=>'البريد غير مسجل']);
            $otp = rand(10000, 99999);
            update_user_meta($user->ID, 'diw_reset_otp', $otp);
            wp_mail($email, 'استعادة كلمة المرور', "رمز الاستعادة: $otp");
            wp_send_json_success();
        }
        elseif ($mode === 'reset_confirm') {
            $email = sanitize_email($_POST['email']);
            $user = get_user_by('email', $email);
            $saved = get_user_meta($user->ID, 'diw_reset_otp', true);
            if ($saved && $saved == $_POST['otp']) {
                wp_set_password($_POST['pass'], $user->ID);
                delete_user_meta($user->ID, 'diw_reset_otp');
                wp_send_json_success();
            } else wp_send_json_error(['msg'=>'رمز خاطئ']);
        }
    }
}

WP_Digital_Identity::get_instance();
