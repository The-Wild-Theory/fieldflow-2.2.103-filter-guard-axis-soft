<?php
namespace RoutesPro\Admin;

use RoutesPro\Repositories\SystemLogRepository;
use RoutesPro\Support\AdminPage;
use RoutesPro\Support\Logger;
use RoutesPro\Support\Request;

if (!defined('ABSPATH')) exit;

class SystemLogs {
    public static function render() {
        if (!current_user_can('routespro_manage')) return;

        $notice = null;
        if (Request::verifyNonce('routespro_logs_nonce', 'routespro_logs_action')) {
            $action = sanitize_key((string) ($_POST['routespro_logs_action'] ?? ''));
            if ($action === 'prune_30d') {
                $deleted = SystemLogRepository::pruneOlderThanDays(30);
                Logger::info('Limpeza manual de logs técnicos executada.', ['context_key' => 'system_logs'], ['deleted_rows' => $deleted]);
                $notice = sprintf('%d registos antigos removidos.', $deleted);
            }
        }

        $rows = SystemLogRepository::latest(150);
        AdminPage::open('Logs técnicos', 'Registo interno para troubleshooting, migrações e manutenção do produto.');
        if ($notice) {
            echo '<div class="notice notice-success"><p>' . esc_html($notice) . '</p></div>';
        }
        ?>
        <style>
          .ff-log-chip{display:inline-block;padding:4px 9px;border-radius:999px;font-size:12px;font-weight:700}
          .ff-log-chip.debug{background:#e2e8f0;color:#334155}.ff-log-chip.info{background:#dbeafe;color:#1d4ed8}.ff-log-chip.warning{background:#fef3c7;color:#92400e}.ff-log-chip.error{background:#fee2e2;color:#991b1b}
          .ff-log-pre{white-space:pre-wrap;max-width:420px;overflow:auto;font-size:12px;line-height:1.45}
        </style>
        <form method="post" style="margin:16px 0 18px">
            <?php wp_nonce_field('routespro_logs_action', 'routespro_logs_nonce'); ?>
            <input type="hidden" name="routespro_logs_action" value="prune_30d" />
            <button class="button">Limpar logs com mais de 30 dias</button>
        </form>
        <table class="widefat striped">
          <thead><tr><th>Data</th><th>Nível</th><th>Contexto</th><th>Mensagem</th><th>Utilizador</th><th>Meta</th></tr></thead>
          <tbody>
          <?php if (empty($rows)): ?>
            <tr><td colspan="6">Sem logs técnicos disponíveis.</td></tr>
          <?php else: foreach ($rows as $row): ?>
            <tr>
              <td><?php echo esc_html((string) ($row['created_at'] ?? '')); ?></td>
              <td><span class="ff-log-chip <?php echo esc_attr((string) ($row['log_level'] ?? 'info')); ?>"><?php echo esc_html(strtoupper((string) ($row['log_level'] ?? 'info'))); ?></span></td>
              <td><code><?php echo esc_html((string) ($row['context_key'] ?? 'system')); ?></code></td>
              <td><?php echo esc_html((string) ($row['message'] ?? '')); ?></td>
              <td><?php echo esc_html((string) ($row['user_id'] ?? '')); ?></td>
              <td><div class="ff-log-pre"><?php echo esc_html((string) ($row['meta_json'] ?? '')); ?></div></td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
        <?php
        AdminPage::close();
    }
}
