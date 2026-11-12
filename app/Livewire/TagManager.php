<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\Tag;
use App\Models\User;
use App\Services\Tags\TagDeleter;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;

final class TagManager extends Component
{
    public bool $showFormModal = false;

    #[Locked]
    public ?int $editingTagId = null;

    public string $name = '';

    public string $colour = '';

    public bool $showDeleteModal = false;

    #[Locked]
    public ?int $deletingTagId = null;

    public string $deletingTagName = '';

    public ?int $replacementTagId = null;

    public bool $showDeleteAllModal = false;

    public function openCreateModal(): void
    {
        $this->resetErrorBag();
        $this->editingTagId = null;
        $this->name = '';
        $this->colour = Arr::random(Tag::PALETTE);
        $this->showFormModal = true;
    }

    public function openEditModal(int $tagId): void
    {
        $tag = $this->ownedTag($tagId);

        if ($tag === null) {
            return;
        }

        $this->resetErrorBag();
        $this->editingTagId = $tag->id;
        $this->name = $tag->name;
        $this->colour = $tag->colour;
        $this->showFormModal = true;
    }

    public function saveTag(): void
    {
        $userId = $this->authenticatedUser()->id;

        $this->name = mb_trim($this->name);

        $this->validate([
            'name' => [
                'required',
                'string',
                'max:50',
                Rule::notIn([Tag::RESERVED_NAME]),
                Rule::unique('tags', 'name')->where('user_id', $userId)->ignore($this->editingTagId),
            ],
            'colour' => ['required', 'regex:/^#[0-9A-Fa-f]{6}\z/'],
        ]);

        if ($this->editingTagId === null) {
            Tag::query()->create(['user_id' => $userId, 'name' => $this->name, 'colour' => $this->colour]);
        } else {
            $tag = $this->ownedTag($this->editingTagId);

            if ($tag === null) {
                return;
            }

            $tag->update(['name' => $this->name, 'colour' => $this->colour]);
        }

        $this->showFormModal = false;
        $this->editingTagId = null;
    }

    public function confirmDelete(int $tagId): void
    {
        $tag = $this->ownedTag($tagId);

        if ($tag === null) {
            return;
        }

        $this->resetErrorBag();
        $this->deletingTagId = $tag->id;
        $this->deletingTagName = $tag->name;
        $this->replacementTagId = null;
        $this->showDeleteModal = true;
    }

    public function deleteTag(TagDeleter $deleter): void
    {
        $this->resetErrorBag();

        if ($this->deletingTagId === null) {
            return;
        }

        if ($this->ownedTag($this->deletingTagId) === null) {
            $this->closeDeleteModal();

            return;
        }

        $userId = $this->authenticatedUser()->id;

        if ($this->replacementTagId !== null) {
            if ($this->replacementTagId === $this->deletingTagId) {
                $this->addError('replacementTagId', __('A tag cannot be replaced by itself.'));

                return;
            }

            if ($this->ownedTag($this->replacementTagId) === null) {
                $this->addError('replacementTagId', __('Choose one of your own tags as the replacement.'));

                return;
            }
        }

        $deleter->delete($userId, $this->deletingTagId, $this->replacementTagId);

        $this->closeDeleteModal();
    }

    public function closeDeleteModal(): void
    {
        $this->showDeleteModal = false;
        $this->deletingTagId = null;
        $this->deletingTagName = '';
        $this->replacementTagId = null;
    }

    public function confirmDeleteAll(): void
    {
        $this->showDeleteAllModal = true;
    }

    public function deleteAllTags(TagDeleter $deleter): void
    {
        $deleter->deleteAll($this->authenticatedUser()->id);

        $this->showDeleteAllModal = false;
        $this->closeDeleteModal();
    }

    public function render(): View
    {
        $userId = $this->authenticatedUser()->id;

        $tags = Tag::query()
            ->where('user_id', $userId)
            ->withCount(['transactions' => fn ($query) => $query->where('transactions.user_id', $userId)->current()])
            ->orderBy('name')
            ->get();

        return view('livewire.tag-manager', [
            'tags' => $tags,
            'palette' => Tag::PALETTE,
        ]);
    }

    private function ownedTag(int $tagId): ?Tag
    {
        return Tag::query()->where('user_id', $this->authenticatedUser()->id)->find($tagId);
    }

    private function authenticatedUser(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
