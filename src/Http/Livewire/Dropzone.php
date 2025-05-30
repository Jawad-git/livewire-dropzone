<?php

namespace Dasundev\LivewireDropzone\Http\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Modelable;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

class Dropzone extends Component
{
    use WithFileUploads;

    #[Modelable]
    public ?array $files;

    #[Locked]
    public array $rules;

    #[Locked]
    public string $uuid;

    public $upload;

    public string $error;

    public bool $multiple;

    // Add your new property here
    public array $oldDocs = [];

    public function rules(): array
    {
        $field = $this->multiple ? 'upload.*' : 'upload';

        return [
            $field => [...$this->rules],
        ];
    }

    public function mount(array $rules = [], bool $multiple = false, array $files = []): void
    {
        $this->uuid = Str::uuid();
        $this->multiple = $multiple;
        $this->rules = $rules;
        $this->files = $files;
    }

    public function updatedUpload(): void
    {
        $this->reset('error');

        try {
            $this->validate();
        } catch (ValidationException $e) {
            // If the upload validation fails, we trigger the following event
            $this->dispatch("{$this->uuid}:uploadError", $e->getMessage());

            return;
        }

        $this->upload = $this->multiple
            ? $this->upload
            : [$this->upload];

        foreach ($this->upload as $upload) {
            $this->handleUpload($upload);
        }

        $this->reset('upload');
    }

    /**
     * Handle the uploaded file and dispatch an event with file details.
     */
    public function handleUpload(TemporaryUploadedFile $file): void
    {
        $this->dispatch("{$this->uuid}:fileAdded", [
            'tmpFilename' => $file->getFilename(),
            'name' => $file->getClientOriginalName(),
            'extension' => $file->extension(),
            'path' => $file->path(),
            'temporaryUrl' => $file->isPreviewable() ? $file->temporaryUrl() : null,
            'size' => $file->getSize(),
        ]);
    }

    /**
     * Handle the file added event.
     */
    #[On('{uuid}:fileAdded')]
    public function onFileAdded(array $file): void
    {
        $this->files = $this->multiple ? array_merge($this->files, [$file]) : [$file];
        if ($this->oldDocs && count($this->oldDocs) > 0 && $this->multiple == false) {
            $this->dispatch('removeOldUpload', $this->oldDocs[0]['id']);
            $this->onRemoveOldDocEntryInDropzone($this->oldDocs[0]['id']);
            $this->oldDocs = array_values($this->oldDocs);
        }
    }

    #[On('removeOldDocEntryInDropzone')]
    public function onRemoveOldDocEntryInDropzone($id): void
    {
        foreach ($this->oldDocs as $index => $doc) {
            if ($doc['id'] == $id) {
                unset($this->oldDocs[$index]); // remove from the actual array
                break;
            }
        }
        $this->oldDocs = array_values($this->oldDocs);
    }

    /**
     * Handle the file removal event.
     */
    #[On('{uuid}:fileRemoved')]
    public function onFileRemoved(string $tmpFilename): void
    {
        $this->files = array_filter($this->files, function ($file) use ($tmpFilename) {
            // Remove the temporary file from the array only.
            // No need to remove from the Livewire's temporary upload directory manually.
            // Because, files older than 24 hours cleanup automatically by Livewire.
            // For more details, refer to: https://livewire.laravel.com/docs/uploads#configuring-automatic-file-cleanup
            return $file['tmpFilename'] !== $tmpFilename;
        });
    }

    /**
     * Handle the upload error event.
     */
    #[On('{uuid}:uploadError')]
    public function onUploadError(string $error): void
    {
        $this->error = $error;
    }

    /**
     * Retrieve the MIME types from the rules.
     */
    #[Computed]
    public function mimes(): string
    {
        return collect($this->rules)
            ->filter(fn($rule) => str_starts_with($rule, 'mimes:'))
            ->flatMap(fn($rule) => explode(',', substr($rule, strpos($rule, ':') + 1)))
            ->unique()
            ->values()
            ->join(', ');
    }

    /**
     * Get the accepted file extensions based on MIME types.
     */
    #[Computed]
    public function accept(): ?string
    {
        return ! empty($this->mimes) ? collect(explode(', ', $this->mimes))->map(fn($mime) => '.' . $mime)->implode(',') : null;
    }

    /**
     * Get the maximum file size in a human-readable format.
     */
    #[Computed]
    public function maxFileSize(): ?string
    {
        return collect($this->rules)
            ->filter(fn($rule) => str_starts_with($rule, 'max:'))
            ->flatMap(fn($rule) => explode(',', substr($rule, strpos($rule, ':') + 1)))
            ->unique()
            ->values()
            ->first();
    }

    /**
     * Checks if the provided MIME type corresponds to an image.
     */
    public function isImageMime($mime): bool
    {
        return in_array($mime, ['png', 'gif', 'bmp', 'svg', 'jpeg', 'jpg']);
    }

    public function render(): View
    {
        return view('livewire-dropzone::livewire.dropzone');
    }
}
