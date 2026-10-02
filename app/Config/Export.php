<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Filesystem settings of CUSTOMERS_LIST exports (web + `export:process`).
 *
 * Every property can be overridden from .env (export.<name>), e.g.
 *   export.openSpoutTempPath = /var/www/extractor/writable/tmp/openspout_tmp
 *
 * Permissions are NOT managed here: the directories are prepared by the
 * deployment / systemd unit (docs/deploy/prepare-writable.sh), PHP only
 * checks them (`php spark export:doctor`).
 */
class Export extends BaseConfig
{
    /**
     * Parent of the per-export OpenSpout scratch folders (XLSX only):
     * <openSpoutTempPath>/export_<date>_<rand>/xlsx<uniqid>/... — about twice
     * the final workbook size while an XLSX is being written, removed at the
     * end of each export (success, failure, cancellation).
     *
     * OpenSpout itself never creates this folder (Options::setTempFolder()
     * requires an existing writable one, its default is sys_get_temp_dir()):
     * CustomerListExportService creates it on demand when its parent is
     * writable, systemd (ExecStartPre) prepares it before the worker starts.
     *
     * Must be dedicated to these exports: folders named export_* older than
     * 6 h in it are deleted as orphans.
     */
    public string $openSpoutTempPath = WRITEPATH . 'tmp/openspout_tmp';

    /** Normalised path, no trailing separator; empty override = default. */
    public function openSpoutTempPath(): string
    {
        $path = trim($this->openSpoutTempPath);
        $path = rtrim($path !== '' ? $path : WRITEPATH . 'tmp/openspout_tmp', '/\\');

        // WRITEPATH ends with "\" on Windows: one separator style in messages.
        return DIRECTORY_SEPARATOR === '\\' ? str_replace('/', '\\', $path) : $path;
    }
}
