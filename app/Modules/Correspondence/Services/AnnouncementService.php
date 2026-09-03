<?php

namespace App\Modules\Correspondence\Services;

use App\Modules\Correspondence\Models\Announcement;

class AnnouncementService
{
    public function create(array $data, int $createdBy): Announcement
    {
        return Announcement::query()->create($data + ['created_by' => $createdBy, 'is_active' => true]);
    }

    public function update(Announcement $announcement, array $data): Announcement
    {
        $announcement->update($data);

        return $announcement->refresh();
    }
}
