<?php

namespace GlpiPlugin\Termodocs\Signature;

use CronTask;
use GlpiPlugin\Termodocs\Document;

/**
 * Polling fallback/complement to the webhook (front/webhook.php):
 * periodically re-checks every WAITING_ACCEPTANCE document sent to
 * Assinei.digital in case the webhook was never registered - Assinei's
 * own docs describe webhook registration as something done by contacting
 * their operations team, not a self-service toggle in the portal, so
 * this cron is the path that works without that coordination (and stays
 * useful afterwards too, as a safety net for a lost delivery).
 */
class AssineiPoller
{
    public static function cronCheckSignatures(CronTask $task): int
    {
        if (!AssineiConfig::isActive()) {
            return 0;
        }

        $checked = 0;
        $updated = 0;
        $provider = new AssineiDigitalProvider();

        $document = new Document();
        foreach ($document->find([
            'signature_provider' => AssineiDigitalProvider::KEY,
            'status'             => Document::WAITING_ACCEPTANCE,
            'is_deleted'         => 0,
        ]) as $row) {
            if (empty($row['external_reference'])) {
                continue;
            }

            $checked++;
            $doc = new Document();
            if (!$doc->getFromDB($row['id'])) {
                continue;
            }

            if ($provider->reconcile($doc) > 0) {
                $updated++;
            }
        }

        $task->setVolume($checked);
        $task->log(sprintf('%d documento(s) verificado(s) na Assinei.digital, %d atualizado(s).', $checked, $updated));

        return $checked > 0 ? 1 : 0;
    }
}
