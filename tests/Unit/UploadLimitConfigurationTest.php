<?php

namespace Tests\Unit;

use Tests\TestCase;

class UploadLimitConfigurationTest extends TestCase
{
    public function test_livewire_and_transport_limits_support_five_twenty_megabyte_files(): void
    {
        $this->assertContains(
            'max:'.config('data-import.max_file_kilobytes'),
            config('livewire.temporary_file_upload.rules'),
        );
        $this->assertSame(20 * 1024, config('data-import.max_file_kilobytes'));

        $phpConfiguration = file_get_contents(base_path('docker/php/php.ini'));
        $this->assertStringContainsString('upload_max_filesize=20M', $phpConfiguration);
        $this->assertStringContainsString('post_max_size=110M', $phpConfiguration);

        $this->assertStringContainsString(
            'client_max_body_size 110m;',
            file_get_contents(base_path('docker/nginx/default.conf')),
        );
        $this->assertStringContainsString(
            'client_max_body_size 110m;',
            file_get_contents(base_path('docker/nginx/production.conf')),
        );
    }
}
