<?php

namespace App\Modules\Correspondence\Http\Controllers;

use App\Modules\Correspondence\Models\Announcement;
use App\Modules\Correspondence\Services\AnnouncementService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AnnouncementController
{
    public function __construct(private readonly AnnouncementService $announcements) {}

    public function index(): View
    {
        return view('correspondence::pengumuman.index', [
            'pengumuman' => Announcement::query()->latest('starts_at')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:5000'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
        ], [], ['title' => 'judul', 'body' => 'isi', 'starts_at' => 'mulai tayang', 'ends_at' => 'selesai tayang']);

        $this->announcements->create($data, $request->user()->id);

        return back()->with('sukses', 'Pengumuman ditambahkan.');
    }

    public function update(Request $request, Announcement $pengumuman): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:5000'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'is_active' => ['nullable', 'boolean'],
        ], [], ['title' => 'judul', 'body' => 'isi']);

        $data['is_active'] = $request->boolean('is_active');

        $this->announcements->update($pengumuman, $data);

        return back()->with('sukses', 'Pengumuman diperbarui.');
    }
}
