<?php

declare(strict_types=1);

namespace App\Http\Controllers\Expenses;

use App\Domain\Access\Enums\Permission;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Documents\Actions\StoreAttachment;
use App\Domain\Documents\Exceptions\AttachmentRefused;
use App\Domain\Documents\Models\Attachment;
use App\Domain\Expenses\Models\Expense;
use App\Http\Controllers\Controller;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Receipts on an expense.
 *
 * The file is STREAMED through this controller rather than served from a
 * storage URL, presigned or otherwise. A receipt is a financial record: a URL
 * that works without a session is a document that leaks with no audit trail,
 * no permission check, and no expiry anybody can rely on. The cost is that
 * every view goes through PHP, which for a handful of receipts per expense is
 * nothing.
 *
 * @see docs/adr/0002-multi-tenancy.md
 */
final class ReceiptController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly StoreAttachment $storeAttachment,
        private readonly AuditRecorder $audit,
    ) {}

    public function store(Request $request, string $number): RedirectResponse
    {
        $this->authorize(Permission::ExpensesUpdate->value);

        $expense = Expense::query()->where('number', $number)->firstOrFail();

        if (! $expense->status->isEditable()) {
            return back()->with(
                'error',
                AttachmentRefused::notEditable($expense->status->label())->getMessage(),
            );
        }

        $request->validate([
            /*
             * The size and type are checked here AND in the action. Here so
             * the user gets a field error rather than an exception page; in
             * the action because an import or an API call has to hit the same
             * limits, and because only the action reads the file's actual
             * type rather than the one the browser claimed.
             */
            'receipt' => [
                'required',
                'file',
                'max:'.(StoreAttachment::MAX_BYTES / 1024),
                'mimetypes:'.implode(',', StoreAttachment::PERMITTED_MIME_TYPES),
            ],
        ]);

        $file = $request->file('receipt');

        if (! $file instanceof UploadedFile) {
            return back()->withErrors(['receipt' => 'No file arrived. Try again.']);
        }

        try {
            $attachment = $this->storeAttachment->handle(
                attachable: $expense,
                file: $file,
                actor: $request->user(),
            );
        } catch (AttachmentRefused $exception) {
            return back()->withErrors(['receipt' => $exception->getMessage()]);
        }

        return back()->with('success', "{$attachment->original_name} attached.");
    }

    /**
     * Serve a receipt.
     *
     * Inline for an image or a PDF, because the point is to look at it beside
     * the expense; as a download for anything else. The filename is the one
     * the user uploaded, sanitised at upload time so it is safe in a header.
     */
    public function show(Request $request, string $number, Attachment $attachment): StreamedResponse
    {
        $this->authorize(Permission::ExpensesView->value);

        $expense = Expense::query()->where('number', $number)->firstOrFail();

        $this->guardBelongsTo($attachment, $expense);

        $disk = Storage::disk($attachment->disk);

        abort_unless($disk->exists($attachment->path), 404);

        $inline = $attachment->isImage() || $attachment->isPdf();

        return $disk->response(
            $attachment->path,
            $attachment->original_name,
            [
                'Content-Type' => $attachment->mime_type,
                /*
                 * A receipt is never public and never cached by a proxy: it
                 * is somebody's expense claim, and an intermediary holding a
                 * copy is a copy nobody can account for.
                 */
                'Cache-Control' => 'private, no-store',
                // Belt and braces on an image that is really a script: the
                // browser is told not to sniff, so a mislabelled file cannot
                // execute in our origin.
                'X-Content-Type-Options' => 'nosniff',
            ],
            $inline ? 'inline' : 'attachment',
        );
    }

    public function destroy(Request $request, string $number, Attachment $attachment): RedirectResponse
    {
        $this->authorize(Permission::ExpensesUpdate->value);

        $expense = Expense::query()->where('number', $number)->firstOrFail();

        $this->guardBelongsTo($attachment, $expense);

        if (! $expense->status->isEditable()) {
            return back()->with(
                'error',
                AttachmentRefused::notEditable($expense->status->label())->getMessage(),
            );
        }

        $name = $attachment->original_name;

        /*
         * The row goes first, then the object.
         *
         * A failed object delete then leaves an unreferenced file, which is
         * wasteful but harmless. The other order would leave a listing
         * showing a receipt that cannot be opened, which looks like data loss
         * and is much harder to explain.
         */
        $disk = $attachment->disk;
        $path = $attachment->path;

        $this->audit->record(
            action: 'documents.attachment_removed',
            subject: $expense,
            description: "Removed receipt {$name} from expense {$expense->number}",
            old: ['name' => $name, 'checksum' => $attachment->checksum],
            actor: $request->user(),
        );

        $attachment->delete();

        Storage::disk($disk)->delete($path);

        return back()->with('success', "{$name} removed.");
    }

    /**
     * A receipt reached by the wrong expense's URL is not found.
     *
     * Both halves matter: the attachment has to belong to this organisation
     * — the route binding is scoped, but saying so twice costs nothing — and
     * to this expense, so an id guessed from another record cannot be read
     * through a URL the user does have access to.
     */
    private function guardBelongsTo(Attachment $attachment, Expense $expense): void
    {
        abort_unless(
            $attachment->organization_id === $this->tenant->organization()->getKey()
                && $attachment->attachable_type === $expense->getMorphClass()
                && $attachment->attachable_id === $expense->getKey(),
            404,
        );
    }
}
