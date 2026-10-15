#!/usr/bin/env python3
"""WP Incident Sweeper 言語ファイル生成・コンパイルスクリプト

1. wp-incident-sweeper.php から翻訳対象文字列（wp-incident-sweeper ドメイン）を自動抽出
2. wp-incident-sweeper/languages/wp-incident-sweeper.pot を生成
3. 各言語（ja, en_US, id_ID, es_ES, de_DE）の .po を生成
4. msgfmt で各言語の .mo をコンパイル
"""

import os
import re
import shutil
import subprocess
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PLUGIN_DIR = ROOT / "wp-incident-sweeper"
LANG_DIR = PLUGIN_DIR / "languages"
PHP_FILE = PLUGIN_DIR / "wp-incident-sweeper.php"

# 国際化対象言語設定
TARGET_LANGS = {
    "ja": {
        "name": "Japanese",
        "team": "Japanese <info@chromatri.be>",
        "locale": "ja",
        "plural": "nplurals=1; plural=0;",
    },
    "en_US": {
        "name": "English (US)",
        "team": "English <info@chromatri.be>",
        "locale": "en_US",
        "plural": "nplurals=2; plural=(n != 1);",
    },
    "id_ID": {
        "name": "Indonesian",
        "team": "Indonesian <info@chromatri.be>",
        "locale": "id_ID",
        "plural": "nplurals=1; plural=0;",
    },
    "es_ES": {
        "name": "Spanish (Spain)",
        "team": "Spanish <info@chromatri.be>",
        "locale": "es_ES",
        "plural": "nplurals=2; plural=(n != 1);",
    },
    "de_DE": {
        "name": "German",
        "team": "German <info@chromatri.be>",
        "locale": "de_DE",
        "plural": "nplurals=2; plural=(n != 1);",
    },
}

TRANSLATIONS: dict[str, dict[str, str]] = {
    "en_US": {
        "WP Incident Sweeper はサイト内のセキュリティ維持およびインシデント発生時の復旧支援ツールです。以下の文面は、サイト管理者がプライバシーポリシーに追加・調整できる参考テンプレートです。": (
            "WP Incident Sweeper is a tool for maintaining site security and supporting recovery during security incidents. "
            "The text below is a reference template that site administrators can adapt and include in their Privacy Policy."
        ),
        "セキュリティ監査および脅威検知": "Security Audit & Threat Detection",
        "当サイトでは、不正な改ざんやスパム投稿、不正アクセスの兆候を検知・監視するため、管理ツール（WP Incident Sweeper）を使用しています。本ツールによるコンテンツおよびアカウントの照合処理はすべて当サイトのサーバー内部で完結しており、投稿本文や利用者の個人データが外部第三者へ送信・共有されることはありません。": (
            "This site uses the WP Incident Sweeper administrative tool to detect and monitor signs of unauthorized tampering, spam posts, and illicit access. "
            "All content and account verification performed by this tool is executed entirely on our own server; "
            "no post content or personal data of users is ever transmitted or shared with external third parties."
        ),
        "シグネチャ更新に伴う外部通信（電気通信事業法外部送信規律への対応）": "External Communication for Threat Signature Updates",
        "脅威シグネチャ（既知の改ざん文字列や悪性ドメイン一覧）の最新化のため、当サイト管理者はGitHubサーバー（raw.githubusercontent.com）からシグネチャ定義ファイルを取得することがあります。この通信において送信される情報はHTTP接続に必要な標準的情報（IPアドレス、User-Agent）に限られ、サイト利用者や投稿内容等のデータは一切含まれません。": (
            "To keep threat signatures (lists of known defacement strings and malicious domains) up to date, the site administrator may retrieve signature definition files from GitHub (raw.githubusercontent.com). "
            "The information transmitted during this communication is limited strictly to standard HTTP connection parameters (IP address, User-Agent), "
            "and contains no site user data or post content whatsoever."
        ),
        "WP Incident Sweeper": "WP Incident Sweeper",
        "WP Incident Sweeper マイKW設定": "WP Incident Sweeper Custom Keywords Settings",
        "WP Incident Sweeper 設定": "WP Incident Sweeper Settings",
        "マイKW（個別検知キーワード）": "Custom Keywords (User Signatures)",
        "インシデント巡回スイーパー": "Incident Sweeper Patrol",
        "巡回スイーパー": "Incident Sweeper",
        "インシデント巡回スイーパー（ネットワーク全体）": "Incident Sweeper Patrol (Network-Wide)",
        "巡回スイーパー(全傘下サイト)": "Incident Sweeper (All Network Sites)",
        "スキャン中...": "Scanning...",
        "全傘下サイトを巡回クロール中...": "Crawling all network sites...",
        "再スキャン実行": "Run Re-scan",
        "全サイト横断スキャン実行": "Run Cross-Site Scan",
        "サイト内を高速クロール中（全投稿・固定ページ・ユーザー）...": "Fast-crawling site content (posts, pages, and users)...",
        "全傘下サイトの全コンテンツ・ユーザーを横断クロール中...": "Cross-crawling all contents and users across all network sites...",
        "ユーザーをスキャン中...": "Scanning users...",
        "該当するコンテンツはありません（安全です）": "No matching content found (Clean & Safe)",
        "ユーザーはいません": "No suspicious users found",
        "正常": "Normal",
        "🚨 危険（即削除推奨）": "🚨 Critical (Immediate Deletion Recommended)",
        "⚠️ 警告（要確認）": "⚠️ Warning (Review Required)",
        "ℹ️ 下書き/ゴミ箱": "ℹ️ Draft / Trash",
        "🚨 不審（即削除推奨）": "🚨 Suspicious (Immediate Deletion Recommended)",
        "トップページ": "Front Page",
        "投稿一覧ページ": "Posts Page",
        "プライバシーポリシー": "Privacy Policy",
        "システム重要ページのため削除保護": "Protected system page (Deletion locked)",
        "表示": "View",
        "編集": "Edit",
        "【安全確認: 記事の完全削除】\n選択された %d 件のコンテンツをデータベースから【完全に消去】します。\n\n※この操作は元に戻せません。公開中の正規記事は自動的にスキップまたはゴミ箱へ退避されます。\n\n本当に実行しますか？": (
            "[Confirmation: Permanent Deletion]\nPermanently delete %d selected item(s) from the database.\n\n"
            "Note: This action cannot be undone. Published legitimate articles will be automatically skipped or moved to Trash.\n\n"
            "Are you sure you want to proceed?"
        ),
        "【確認: ゴミ箱への移動】\n選択された %d 件のコンテンツをゴミ箱へ移動します。\n（後から復元可能です）\n\n実行しますか？": (
            "[Confirmation: Move to Trash]\nMove %d selected item(s) to the Trash.\n(Items can be restored later)\n\nProceed?"
        ),
        "【安全確認: 不審ユーザーの削除】\n選択された %d 名の不審ユーザーを削除します。\n\n🛡️ 巻き込み防止ガード:\n・不正な下書き・ゴミ箱記事のみが完全消去されます。\n・公開中の正規記事は安全に保護されます。\n\n実行しますか？": (
            "[Confirmation: Delete Suspicious Users]\nDelete %d selected suspicious user(s).\n\n"
            "🛡️ Safety Guard:\n- Only unauthorized draft or trashed posts will be purged.\n- Published legitimate posts remain safely protected.\n\n"
            "Proceed?"
        ),
        "選択した%d名の不審ユーザーを削除": "Delete %d selected suspicious user(s)",
        "選択した不審ユーザーを削除": "Delete selected suspicious users",
        "エクスポートするスキャンデータがありません。先にスキャンを実行してください。": "No scan data to export. Please run a scan first.",
        "同期中...": "Syncing...",
        "保存中...": "Saving...",
        "マイKWを保存しました！": "Custom keywords saved successfully!",
        "🎯 マイKW検知": "🎯 Custom Keyword Match",
        "%d サイト": "%d site(s)",
        "%d 件のコンテンツを処理しました。\n（完全削除: %d件 / ゴミ箱移動: %d件 / スキップ: %d件）": "Processed %d content item(s).\n(Purged: %d / Trashed: %d / Skipped: %d)",
        "%d 名のユーザーの削除が完了しました。": "Successfully deleted %d user(s).",
        "\n（※正規ユーザーや自身のアカウント %d 名は安全のため自動スキップされました）": "\n(* %d legitimate or active account(s) were automatically skipped for safety)",
        "法令・規約・プライバシー": "Legal, Terms & Privacy",
        "法令遵守（コンプライアンス）・プライバシー・利用規約": "Compliance, Privacy & Terms of Use",
        "WP Incident Sweeper は、各種法令（電気通信事業法、個人情報保護法、不正アクセス禁止法等）およびWordPress標準プライバシーAPIに完全準拠して設計されています。": (
            "WP Incident Sweeper is designed to be fully compliant with global privacy frameworks, cybersecurity laws, and standard WordPress Privacy APIs."
        ),
        "電気通信事業法（外部送信規律）への対応": "External Transmission Regulation Disclosure",
        "個人情報保護法 (APPI) & GDPR 対応": "Data Protection (APPI & GDPR) Compliance",
        "不正アクセス禁止法遵守 & 利用許諾条項": "Cybersecurity Act Compliance & License Terms",
        "免責事項 (Limitation of Liability) & バックアップ義務": "Limitation of Liability & Prerequisite Backup Obligation",
    },
    "id_ID": {
        "WP Incident Sweeper はサイト内のセキュリティ維持およびインシデント発生時の復旧支援ツールです。以下の文面は、サイト管理者がプライバシーポリシーに追加・調整できる参考テンプレートです。": (
            "WP Incident Sweeper adalah alat untuk menjaga keamanan situs dan membantu pemulihan saat terjadi insiden keamanan. "
            "Teks di bawah ini adalah templat referensi yang dapat disesuaikan dan ditambahkan oleh administrator ke Kebijakan Privasi situs."
        ),
        "セキュリティ監査および脅威検知": "Audit Keamanan & Deteksi Ancaman",
        "当サイトでは、不正な改ざんやスパム投稿、不正アクセスの兆候を検知・監視するため、管理ツール（WP Incident Sweeper）を使用しています。本ツールによるコンテンツおよびアカウントの照合処理はすべて当サイトのサーバー内部で完結しており、投稿本文や利用者の個人データが外部第三者へ送信・共有されることはありません。": (
            "Situs ini menggunakan alat administratif (WP Incident Sweeper) untuk mendeteksi dan memantau tanda-tanda manipulasi tidak sah, kiriman spam, dan akses ilegal. "
            "Semua proses verifikasi konten dan akun dilakukan sepenuhnya di dalam server situs kami; "
            "tidak ada isi postingan atau data pribadi pengguna yang dikirim atau dibagikan ke pihak ketiga."
        ),
        "シグネチャ更新に伴う外部通信（電気通信事業法外部送信規律への対応）": "Komunikasi Eksternal untuk Pembaruan Tanda Tangan Ancaman",
        "脅威シグネチャ（既知の改ざん文字列や悪性ドメイン一覧）の最新化のため、当サイト管理者はGitHubサーバー（raw.githubusercontent.com）からシグネチャ定義ファイルを取得することがあります。この通信において送信される情報はHTTP接続に必要な標準的情報（IPアドレス、User-Agent）に限られ、サイト利用者や投稿内容等のデータは一切含まれません。": (
            "Untuk memperbarui tanda tangan ancaman (daftar kata kunci defacement dan domain berbahaya yang diketahui), administrator situs dapat mengunduh berkas definisi tanda tangan dari server GitHub (raw.githubusercontent.com). "
            "Informasi yang dikirimkan dalam koneksi ini terbatas pada parameter koneksi HTTP standar (alamat IP, User-Agent), "
            "dan sama sekali tidak mencakup data pengguna situs atau konten postingan."
        ),
        "WP Incident Sweeper": "WP Incident Sweeper",
        "WP Incident Sweeper マイKW設定": "Pengaturan Kata Kunci Kustom WP Incident Sweeper",
        "WP Incident Sweeper 設定": "Pengaturan WP Incident Sweeper",
        "マイKW（個別検知キーワード）": "Kata Kunci Kustom (Deteksi Mandiri)",
        "インシデント巡回スイーパー": "Patroli Incident Sweeper",
        "巡回スイーパー": "Incident Sweeper",
        "インシデント巡回スイーパー（ネットワーク全体）": "Patroli Incident Sweeper (Seluruh Jaringan)",
        "巡回スイーパー(全傘下サイト)": "Incident Sweeper (Semua Situs Jaringan)",
        "スキャン中...": "Memindai...",
        "全傘下サイトを巡回クロール中...": "Merayapi seluruh situs jaringan...",
        "再スキャン実行": "Jalankan Pindai Ulang",
        "全サイト横断スキャン実行": "Jalankan Pemindaian Lintas Situs",
        "サイト内を高速クロール中（全投稿・固定ページ・ユーザー）...": "Merayapi konten situs dengan cepat (pos, laman, dan pengguna)...",
        "全傘下サイトの全コンテンツ・ユーザーを横断クロール中...": "Merayapi seluruh konten dan pengguna di semua situs jaringan...",
        "ユーザーをスキャン中...": "Memindai pengguna...",
        "該当するコンテンツはありません（安全です）": "Tidak ada konten mencurigakan yang ditemukan (Aman)",
        "ユーザーはいません": "Tidak ada pengguna mencurigakan",
        "正常": "Normal",
        "🚨 危険（即削除推奨）": "🚨 Kritis (Disarankan Segera Dihapus)",
        "⚠️ 警告（要確認）": "⚠️ Peringatan (Perlu Ditinjau)",
        "ℹ️ 下書き/ゴミ箱": "ℹ️ Draf / Tong Sampah",
        "🚨 不審（即削除推奨）": "🚨 Mencurigakan (Disarankan Segera Dihapus)",
        "トップページ": "Halaman Depan",
        "投稿一覧ページ": "Halaman Pos",
        "プライバシーポリシー": "Kebijakan Privasi",
        "システム重要ページのため削除保護": "Halaman sistem penting (Dilindungi dari penghapusan)",
        "表示": "Lihat",
        "編集": "Sunting",
        "【安全確認: 記事の完全削除】\n選択された %d 件のコンテンツをデータベースから【完全に消去】します。\n\n※この操作は元に戻せません。公開中の正規記事は自動的にスキップまたはゴミ箱へ退避されます。\n\n本当に実行しますか？": (
            "[Konfirmasi: Penghapusan Permanen]\nMenghapus %d konten yang dipilih secara PERMANEN dari basis data.\n\n"
            "Catatan: Tindakan ini tidak dapat dibatalkan. Artikel sah yang telah dipublikasikan akan dilewati secara otomatis atau dipindahkan ke Tong Sampah.\n\n"
            "Apakah Anda yakin ingin melanjutkan?"
        ),
        "【確認: ゴミ箱への移動】\n選択された %d 件のコンテンツをゴミ箱へ移動します。\n（後から復元可能です）\n\n実行しますか？": (
            "[Konfirmasi: Pindahkan ke Tong Sampah]\nMemindahkan %d konten yang dipilih ke Tong Sampah.\n(Dapat dipulihkan kembali nanti)\n\nLanjutkan?"
        ),
        "【安全確認: 不審ユーザーの削除】\n選択された %d 名の不審ユーザーを削除します。\n\n🛡️ 巻き込み防止ガード:\n・不正な下書き・ゴミ箱記事のみが完全消去されます。\n・公開中の正規記事は安全に保護されます。\n\n実行しますか？": (
            "[Konfirmasi: Hapus Pengguna Mencurigakan]\nMenghapus %d pengguna mencurigakan yang dipilih.\n\n"
            "🛡️ Perlindungan Keamanan:\n- Hanya pos draf atau sampah tidak sah yang akan dihapus permanen.\n- Pos sah yang telah diterbitkan tetap terlindungi aman.\n\n"
            "Lanjutkan?"
        ),
        "選択した%d名の不審ユーザーを削除": "Hapus %d pengguna mencurigakan yang dipilih",
        "選択した不審ユーザーを削除": "Hapus pengguna mencurigakan yang dipilih",
        "エクスポートするスキャンデータがありません。先にスキャンを実行してください。": "Tidak ada data pindaian untuk diekspor. Silakan jalankan pemindaian terlebih dahulu.",
        "同期中...": "Menyinkronkan...",
        "保存中...": "Menyimpan...",
        "マイKWを保存しました！": "Kata kunci kustom berhasil disimpan!",
        "🎯 マイKW検知": "🎯 Cocok Kata Kunci Kustom",
        "%d サイト": "%d situs",
        "%d 件のコンテンツを処理しました。\n（完全削除: %d件 / ゴミ箱移動: %d件 / スキップ: %d件）": "Memproses %d konten.\n(Dihapus Permanen: %d / Dibuang ke Sampah: %d / Dilewati: %d)",
        "%d 名のユーザーの削除が完了しました。": "Berhasil menghapus %d pengguna.",
        "\n（※正規ユーザーや自身のアカウント %d 名は安全のため自動スキップされました）": "\n(* %d akun sah atau akun Anda sendiri dilewati otomatis demi keamanan)",
        "法令・規約・プライバシー": "Hukum, Ketentuan & Privasi",
        "法令遵守（コンプライアンス）・プライバシー・利用規約": "Kepatuhan Hukum, Privasi & Syarat Penggunaan",
        "WP Incident Sweeper は、各種法令（電気通信事業法、個人情報保護法、不正アクセス禁止法等）およびWordPress標準プライバシーAPIに完全準拠して設計されています。": (
            "WP Incident Sweeper dirancang untuk sepenuhnya mematuhi kerangka regulasi privasi, undang-undang keamanan siber, dan API Privasi standar WordPress."
        ),
        "電気通信事業法（外部送信規律）への対応": "Keterbukaan Transmisi Eksternal Data",
        "個人情報保護法 (APPI) & GDPR 対応": "Kepatuhan Perlindungan Data (APPI & GDPR)",
        "不正アクセス禁止法遵守 & 利用許諾条項": "Kepatuhan UU Keamanan Siber & Ketentuan Lisensi",
        "免責事項 (Limitation of Liability) & バックアップ義務": "Batasan Tanggung Jawab & Kewajiban Pencadangan",
    },
    "es_ES": {
        "WP Incident Sweeper はサイト内のセキュリティ維持およびインシデント発生時の復旧支援ツールです。以下の文面は、サイト管理者がプライバシーポリシーに追加・調整できる参考テンプレートです。": (
            "WP Incident Sweeper es una herramienta para mantener la seguridad del sitio y brindar asistencia en la recuperación ante incidentes. "
            "El siguiente texto es una plantilla de referencia que los administradores del sitio pueden adaptar e incluir en su Política de Privacidad."
        ),
        "セキュリティ監査および脅威検知": "Auditoría de seguridad y detección de amenazas",
        "当サイトでは、不正な改ざんやスパム投稿、不正アクセスの兆候を検知・監視するため、管理ツール（WP Incident Sweeper）を使用しています。本ツールによるコンテンツおよびアカウントの照合処理はすべて当サイトのサーバー内部で完結しており、投稿本文や利用者の個人データが外部第三者へ送信・共有されることはありません。": (
            "Este sitio utiliza la herramienta administrativa WP Incident Sweeper para detectar y supervisar indicios de alteraciones no autorizadas, publicaciones de spam y accesos ilegítimos. "
            "Todo el procesamiento de verificación de contenidos y cuentas se realiza íntegramente en el propio servidor del sitio; "
            "ningún contenido de publicaciones ni datos personales de los usuarios se transmiten o comparten con terceros."
        ),
        "シグネチャ更新に伴う外部通信（電気通信事業法外部送信規律への対応）": "Comunicaciones externas para la actualización de firmas de amenazas",
        "脅威シグネチャ（既知の改ざん文字列や悪性ドメイン一覧）の最新化のため、当サイト管理者はGitHubサーバー（raw.githubusercontent.com）からシグネチャ定義ファイルを取得することがあります。この通信において送信される情報はHTTP接続に必要な標準的情報（IPアドレス、User-Agent）に限られ、サイト利用者や投稿内容等のデータは一切含まれません。": (
            "Para mantener actualizadas las firmas de amenazas (listas de cadenas de alteración y dominios maliciosos conocidos), el administrador del sitio puede descargar archivos de definición de firmas desde los servidores de GitHub (raw.githubusercontent.com). "
            "La información transmitida en esta comunicación se limita a los parámetros estándar de conexión HTTP (dirección IP, User-Agent), "
            "y no contiene ningún dato de los usuarios ni contenido de las publicaciones."
        ),
        "WP Incident Sweeper": "WP Incident Sweeper",
        "WP Incident Sweeper マイKW設定": "Ajustes de palabras clave personalizadas de WP Incident Sweeper",
        "WP Incident Sweeper 設定": "Ajustes de WP Incident Sweeper",
        "マイKW（個別検知キーワード）": "Palabras clave personalizadas (Detección propia)",
        "インシデント巡回スイーパー": "Patrulla de Incident Sweeper",
        "巡回スイーパー": "Incident Sweeper",
        "インシデント巡回スイーパー（ネットワーク全体）": "Patrulla de Incident Sweeper (Toda la red)",
        "巡回スイーパー(全傘下サイト)": "Incident Sweeper (Todos los sitios)",
        "スキャン中...": "Escaneando...",
        "全傘下サイトを巡回クロール中...": "Rastreando todos los sitios de la red...",
        "再スキャン実行": "Ejecutar nuevo escaneo",
        "全サイト横断スキャン実行": "Ejecutar escaneo en toda la red",
        "サイト内を高速クロール中（全投稿・固定ページ・ユーザー）...": "Rastreo rápido del sitio (todas las entradas, páginas y usuarios)...",
        "全傘下サイトの全コンテンツ・ユーザーを横断クロール中...": "Rastreando contenidos y usuarios en todos los sitios de la red...",
        "ユーザーをスキャン中...": "Escaneando usuarios...",
        "該当するコンテンツはありません（安全です）": "No se encontraron contenidos sospechosos (Sitio seguro)",
        "ユーザーはいません": "No se encontraron usuarios sospechosos",
        "正常": "Normal",
        "🚨 危険（即削除推奨）": "🚨 Crítico (Se recomienda eliminación inmediata)",
        "⚠️ 警告（要確認）": "⚠️ Advertencia (Requiere revisión)",
        "ℹ️ 下書き/ゴミ箱": "ℹ️ Borrador / Papelera",
        "🚨 不審（即削除推奨）": "🚨 Sospechoso (Se recomienda eliminación inmediata)",
        "トップページ": "Página de inicio",
        "投稿一覧ページ": "Página de entradas",
        "プライバシーポリシー": "Política de privacidad",
        "システム重要ページのため削除保護": "Página esencial del sistema (Protegida contra eliminación)",
        "表示": "Ver",
        "編集": "Editar",
        "【安全確認: 記事の完全削除】\n選択された %d 件のコンテンツをデータベースから【完全に消去】します。\n\n※この操作は元に戻せません。公開中の正規記事は自動的にスキップまたはゴミ箱へ退避されます。\n\n本当に実行しますか？": (
            "[Confirmación: Eliminación permanente]\nEliminar permanentemente %d elemento(s) seleccionado(s) de la base de datos.\n\n"
            "Nota: Esta acción no se puede deshacer. Los artículos legítimos publicados se omitirán automáticamente o se moverán a la papelera.\n\n"
            "¿Realmente desea continuar?"
        ),
        "【確認: ゴミ箱への移動】\n選択された %d 件のコンテンツをゴミ箱へ移動します。\n（後から復元可能です）\n\n実行しますか？": (
            "[Confirmación: Mover a la papelera]\nMover %d elemento(s) seleccionado(s) a la papelera.\n(Se pueden restaurar posteriormente)\n\n¿Continuar?"
        ),
        "【安全確認: 不審ユーザーの削除】\n選択された %d 名の不審ユーザーを削除します。\n\n🛡️ 巻き込み防止ガード:\n・不正な下書き・ゴミ箱記事のみが完全消去されます。\n・公開中の正規記事は安全に保護されます。\n\n実行しますか？": (
            "[Confirmación: Eliminar usuarios sospechosos]\nEliminar %d usuario(s) sospechoso(s) seleccionado(s).\n\n"
            "🛡️ Protección de seguridad:\n- Solo se eliminarán permanentemente los borradores o entradas no autorizadas en la papelera.\n- Las publicaciones legítimas publicadas permanecerán protegidas.\n\n"
            "¿Continuar?"
        ),
        "選択した%d名の不審ユーザーを削除": "Eliminar %d usuario(s) sospechoso(s) seleccionado(s)",
        "選択した不審ユーザーを削除": "Eliminar usuarios sospechosos seleccionados",
        "エクスポートするスキャンデータがありません。先にスキャンを実行してください。": "No hay datos de escaneo para exportar. Por favor, ejecute un escaneo primero.",
        "同期中...": "Sincronizando...",
        "保存中...": "Guardando...",
        "マイKWを保存しました！": "¡Palabras clave personalizadas guardadas con éxito!",
        "🎯 マイKW検知": "🎯 Coincidencia de palabra clave personalizada",
        "%d サイト": "%d sitio(s)",
        "%d 件のコンテンツを処理しました。\n（完全削除: %d件 / ゴミ箱移動: %d件 / スキップ: %d件）": "Se procesaron %d contenido(s).\n(Eliminados definitivamente: %d / A la papelera: %d / Omitidos: %d)",
        "%d 名のユーザーの削除が完了しました。": "Se eliminaron correctamente %d usuario(s).",
        "\n（※正規ユーザーや自身のアカウント %d 名は安全のため自動スキップされました）": "\n(* %d cuenta(s) legítima(s) o su propia cuenta se omitieron automáticamente por seguridad)",
        "法令・規約・プライバシー": "Legal, Términos y Privacidad",
        "法令遵守（コンプライアンス）・プライバシー・利用規約": "Cumplimiento normativo, privacidad y condiciones de uso",
        "WP Incident Sweeper は、各種法令（電気通信事業法、個人情報保護法、不正アクセス禁止法等）およびWordPress標準プライバシーAPIに完全準拠して設計されています。": (
            "WP Incident Sweeper está diseñado para cumplir plenamente con las normativas de privacidad, leyes de ciberseguridad y las API estándar de privacidad de WordPress."
        ),
        "電気通信事業法（外部送信規律）への対応": "Transparencia en transmisiones de datos externas",
        "個人情報保護法 (APPI) & GDPR 対応": "Cumplimiento de protección de datos (APPI y RGPD)",
        "不正アクセス禁止法遵守 & 利用許諾条項": "Cumplimiento de leyes de ciberseguridad y términos de licencia",
        "免責事項 (Limitation of Liability) & バックアップ義務": "Limitación de responsabilidad y obligación de copia de seguridad previa",
    },
    "de_DE": {
        "WP Incident Sweeper はサイト内のセキュリティ維持およびインシデント発生時の復旧支援ツールです。以下の文面は、サイト管理者がプライバシーポリシーに追加・調整できる参考テンプレートです。": (
            "WP Incident Sweeper ist ein Tool zur Aufrechterhaltung der Websitesicherheit und zur Unterstützung bei der Wiederherstellung nach Sicherheitsvorfällen. "
            "Der folgende Text dient als Vorlage, die Website-Administratoren in ihre Datenschutzerklärung übernehmen und anpassen können."
        ),
        "セキュリティ監査および脅威検知": "Sicherheitsaudit und Bedrohungserkennung",
        "当サイトでは、不正な改ざんやスパム投稿、不正アクセスの兆候を検知・監視するため、管理ツール（WP Incident Sweeper）を使用しています。本ツールによるコンテンツおよびアカウントの照合処理はすべて当サイトのサーバー内部で完結しており、投稿本文や利用者の個人データが外部第三者へ送信・共有されることはありません。": (
            "Diese Website verwendet das Verwaltungstool WP Incident Sweeper zur Erkennung und Überwachung von Anzeichen unbefugter Manipulationen, Spam-Beiträgen und unberechtigtem Zugriff. "
            "Sämtliche Inhalts- und Kontenabgleiche durch dieses Tool erfolgen vollständig lokal auf unserem Server; "
            "es werden keinerlei Beitragsinhalte oder personenbezogene Daten der Nutzer an externe Dritte übertragen oder weitergegeben."
        ),
        "シグネチャ更新に伴う外部通信（電気通信事業法外部送信規律への対応）": "Externe Kommunikation für Signaturaktualisierungen",
        "脅威シグネチャ（既知の改ざん文字列や悪性ドメイン一覧）の最新化のため、当サイト管理者はGitHubサーバー（raw.githubusercontent.com）からシグネチャ定義ファイルを取得することがあります。この通信において送信される情報はHTTP接続に必要な標準的情報（IPアドレス、User-Agent）に限られ、サイト利用者や投稿内容等のデータは一切含まれません。": (
            "Zur Aktualisierung der Bedrohungssignaturen (Listen bekannter Manipulationszeichenfolgen und bösartiger Domains) kann der Website-Administrator Signaturdefinitionsdateien vom GitHub-Server (raw.githubusercontent.com) abrufen. "
            "Die bei dieser Kommunikation übermittelten Daten beschränken sich ausschließlich auf standardmäßige HTTP-Verbindungsdaten (IP-Adresse, User-Agent); "
            "Daten von Website-Nutzern oder Beitragsinhalte sind darin zu keinem Zeitpunkt enthalten."
        ),
        "WP Incident Sweeper": "WP Incident Sweeper",
        "WP Incident Sweeper マイKW設定": "WP Incident Sweeper Eigene Suchbegriffe Einstellungen",
        "WP Incident Sweeper 設定": "WP Incident Sweeper Einstellungen",
        "マイKW（個別検知キーワード）": "Eigene Suchbegriffe (Benutzerdefinierte Erkennung)",
        "インシデント巡回スイーパー": "Vorfall-Patrouille (Incident Sweeper)",
        "巡回スイーパー": "Incident Sweeper",
        "インシデント巡回スイーパー（ネットワーク全体）": "Vorfall-Patrouille (Netzwerkweit)",
        "巡回スイーパー(全傘下サイト)": "Incident Sweeper (Alle Netzwerk-Websites)",
        "スキャン中...": "Scan läuft...",
        "全傘下サイトを巡回クロール中...": "Alle Netzwerk-Websites werden durchsucht...",
        "再スキャン実行": "Erneut scannen",
        "全サイト横断スキャン実行": "Netzwerkweiten Scan ausführen",
        "サイト内を高速クロール中（全投稿・固定ページ・ユーザー）...": "Schneller Website-Crawl läuft (Beiträge, Seiten und Benutzer)...",
        "全傘下サイトの全コンテンツ・ユーザーを横断クロール中...": "Umfassender Crawl aller Inhalte und Benutzer über alle Netzwerk-Websites läuft...",
        "ユーザーをスキャン中...": "Benutzer werden gescannt...",
        "該当するコンテンツはありません（安全です）": "Keine verdächtigen Inhalte gefunden (Sicher)",
        "ユーザーはいません": "Keine verdächtigen Benutzer gefunden",
        "正常": "Normal",
        "🚨 危険（即削除推奨）": "🚨 Kritisch (Sofortige Löschung empfohlen)",
        "⚠️ 警告（要確認）": "⚠️ Warnung (Prüfung erforderlich)",
        "ℹ️ 下書き/ゴミ箱": "ℹ️ Entwurf / Papierkorb",
        "🚨 不審（即削除推奨）": "🚨 Verdächtig (Sofortige Löschung empfohlen)",
        "トップページ": "Startseite",
        "投稿一覧ページ": "Beitragsseite",
        "プライバシーポリシー": "Datenschutzerklärung",
        "システム重要ページのため削除保護": "Systemrelevante Seite (Löschschutz aktiv)",
        "表示": "Ansehen",
        "編集": "Bearbeiten",
        "【安全確認: 記事の完全削除】\n選択された %d 件のコンテンツをデータベースから【完全に消去】します。\n\n※この操作は元に戻せません。公開中の正規記事は自動的にスキップまたはゴミ箱へ退避されます。\n\n本当に実行しますか？": (
            "[Sicherheitsabfrage: Endgültige Löschung]\n%d ausgewählte Inhalte endgültig aus der Datenbank LÖSCHEN.\n\n"
            "Hinweis: Dieser Vorgang kann nicht rückgängig gemacht werden. Veröffentlichte reguläre Beiträge werden automatisch übersprungen oder in den Papierkorb verschoben.\n\n"
            "Möchten Sie wirklich fortfahren?"
        ),
        "【確認: ゴミ箱への移動】\n選択された %d 件のコンテンツをゴミ箱へ移動します。\n（後から復元可能です）\n\n実行しますか？": (
            "[Bestätigung: In den Papierkorb verschieben]\n%d ausgewählte Inhalte in den Papierkorb verschieben.\n(Kann später wiederhergestellt werden)\n\nFortfahren?"
        ),
        "【安全確認: 不審ユーザーの削除】\n選択された %d 名の不審ユーザーを削除します。\n\n🛡️ 巻き込み防止ガード:\n・不正な下書き・ゴミ箱記事のみが完全消去されます。\n・公開中の正規記事は安全に保護されます。\n\n実行しますか？": (
            "[Sicherheitsabfrage: Verdächtige Benutzer löschen]\n%d ausgewählte verdächtige Benutzer löschen.\n\n"
            "🛡️ Schutzfunktion:\n- Nur unbefugte Entwürfe oder Papierkorb-Beiträge werden endgültig gelöscht.\n- Veröffentlichte reguläre Beiträge bleiben geschützt.\n\n"
            "Fortfahren?"
        ),
        "選択した%d名の不審ユーザーを削除": "%d ausgewählte verdächtige Benutzer löschen",
        "選択した不審ユーザーを削除": "Ausgewählte verdächtige Benutzer löschen",
        "エクスポートするスキャンデータがありません。先にスキャンを実行してください。": "Keine Scandaten zum Exportieren vorhanden. Bitte führen Sie zuerst einen Scan durch.",
        "同期中...": "Synchronisiere...",
        "保存中...": "Speichern...",
        "マイKWを保存しました！": "Eigene Suchbegriffe erfolgreich gespeichert!",
        "🎯 マイKW検知": "🎯 Eigener Suchbegriff Treffer",
        "%d サイト": "%d Website(s)",
        "%d 件のコンテンツを処理しました。\n（完全削除: %d件 / ゴミ箱移動: %d件 / スキップ: %d件）": "%d Inhalt(e) verarbeitet.\n(Endgültig gelöscht: %d / Papierkorb: %d / Übersprungen: %d)",
        "%d 名のユーザーの削除が完了しました。": "%d Benutzer erfolgreich gelöscht.",
        "\n（※正規ユーザーや自身のアカウント %d 名は安全のため自動スキップされました）": "\n(* %d reguläre Konten oder das eigene Konto wurden zur Sicherheit automatisch übersprungen)",
        "法令・規約・プライバシー": "Rechtliches, Nutzungsbedingungen & Datenschutz",
        "法令遵守（コンプライアンス）・プライバシー・利用規約": "Compliance, Datenschutz & Nutzungsbedingungen",
        "WP Incident Sweeper は、各種法令（電気通信事業法、個人情報保護法、不正アクセス禁止法等）およびWordPress標準プライバシーAPIに完全準拠して設計されています。": (
            "WP Incident Sweeper wurde unter vollständiger Einhaltung der Datenschutzgesetze (DSGVO / APPI), IT-Sicherheitsgesetze und der Standard-WordPress-Datenschutz-APIs entwickelt."
        ),
        "電気通信事業法（外部送信規律）への対応": "Transparenz bezüglich externer Datenübertragung",
        "個人情報保護法 (APPI) & GDPR 対応": "Datenschutzkonformität (APPI & DSGVO)",
        "不正アクセス禁止法遵守 & 利用許諾条項": "Einhaltung von IT-Sicherheitsgesetzen & Lizenzbedingungen",
        "免責事項 (Limitation of Liability) & バックアップ義務": "Haftungsbeschränkung & Pflicht zur Datensicherung",
    },
}


def extract_strings(php_path: Path) -> list[str]:
    text = php_path.read_text(encoding="utf-8")
    pattern = r"""(?:__|esc_html__|esc_html_e|_e)\(\s*(['"])(.*?)\1\s*,\s*['"]wp-incident-sweeper['"]"""
    matches = re.findall(pattern, text, re.DOTALL)
    
    unique_strings = []
    seen = set()
    for _, s in matches:
        cleaned = s.replace(r"\'", "'").replace(r'\"', '"')
        if cleaned not in seen:
            seen.add(cleaned)
            unique_strings.append(cleaned)
    return unique_strings


def format_po_entry(msgid: str, msgstr: str = "") -> str:
    def escape_str(val: str) -> str:
        lines = val.split("\n")
        if len(lines) == 1:
            return '"' + lines[0].replace('\\', '\\\\').replace('"', '\\"') + '"'
        out = '""\n'
        for idx, line in enumerate(lines):
            suffix = "\\n" if idx < len(lines) - 1 else ""
            out += '    "' + line.replace('\\', '\\\\').replace('"', '\\"') + suffix + '"\n'
        return out.rstrip("\n")

    return f"msgid {escape_str(msgid)}\nmsgstr {escape_str(msgstr)}\n\n"


def make_header(lang_code: str) -> str:
    meta = TARGET_LANGS.get(lang_code, {
        "name": "Template",
        "team": "International <info@chromatri.be>",
        "locale": lang_code,
        "plural": "nplurals=2; plural=(n != 1);",
    })
    return (
        'msgid ""\n'
        'msgstr ""\n'
        '"Project-Id-Version: WP Incident Sweeper 2.20261015.10\\n"\n'
        '"Report-Msgid-Bugs-To: https://chromatri.be\\n"\n'
        '"POT-Creation-Date: 2026-10-15 19:00+0900\\n"\n'
        f'"PO-Revision-Date: 2026-10-15 19:00+0900\\n"\n'
        '"Last-Translator: CHROMATRIBE <info@chromatri.be>\\n"\n'
        f'"Language-Team: {meta["team"]}\\n"\n'
        f'"Language: {meta["locale"]}\\n"\n'
        '"MIME-Version: 1.0\\n"\n'
        '"Content-Type: text/plain; charset=UTF-8\\n"\n'
        '"Content-Transfer-Encoding: 8bit\\n"\n'
        f'"Plural-Forms: {meta["plural"]}\\n"\n'
        '"X-Generator: Python build_languages.py\\n"\n\n'
    )


def find_msgfmt() -> str:
    candidate = "/opt/homebrew/bin/msgfmt"
    if os.path.exists(candidate) and os.access(candidate, os.X_OK):
        return candidate
    in_path = shutil.which("msgfmt")
    if in_path:
        return in_path
    raise FileNotFoundError("msgfmt command not found in system or /opt/homebrew/bin")


def main():
    LANG_DIR.mkdir(parents=True, exist_ok=True)
    strings = extract_strings(PHP_FILE)
    print(f"Extracted {len(strings)} translatable strings from PHP.")

    msgfmt_cmd = find_msgfmt()
    print(f"Using msgfmt: {msgfmt_cmd}")

    # 1. POT テンプレートファイル生成
    pot_path = LANG_DIR / "wp-incident-sweeper.pot"
    with open(pot_path, "w", encoding="utf-8") as f:
        f.write(make_header("template"))
        for s in strings:
            f.write(format_po_entry(s, ""))
    print(f"Written POT template to: {pot_path.name}")

    # 2. 各言語の PO & MO 生成
    for lang_code in TARGET_LANGS:
        po_path = LANG_DIR / f"wp-incident-sweeper-{lang_code}.po"
        mo_path = LANG_DIR / f"wp-incident-sweeper-{lang_code}.mo"

        with open(po_path, "w", encoding="utf-8") as f:
            f.write(make_header(lang_code))
            for s in strings:
                if lang_code == "ja":
                    translated = s
                else:
                    dict_for_lang = TRANSLATIONS.get(lang_code, {})
                    translated = dict_for_lang.get(s, s)
                f.write(format_po_entry(s, translated))

        # msgfmt で MO にコンパイル
        cmd = [msgfmt_cmd, "-o", str(mo_path), str(po_path)]
        res = subprocess.run(cmd, capture_output=True, text=True)
        if res.returncode != 0:
            raise RuntimeError(f"msgfmt failed for {lang_code}: {res.stderr}")

        print(f"Generated and compiled: {po_path.name} -> {mo_path.name} ({mo_path.stat().st_size} bytes)")


if __name__ == "__main__":
    main()
