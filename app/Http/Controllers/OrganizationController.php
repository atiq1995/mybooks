<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Organizations\Actions\CreateOrganization;
use App\Domain\Organizations\Data\NewOrganizationData;
use App\Domain\Organizations\Support\OrganizationOptions;
use App\Http\Middleware\EstablishTenantContext;
use App\Http\Requests\Organizations\StoreOrganizationRequest;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Creating a new organisation — a new set of books.
 */
final class OrganizationController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Organizations/Create', [
            'options' => OrganizationOptions::forForms(),
        ]);
    }

    public function store(
        StoreOrganizationRequest $request,
        CreateOrganization $createOrganization,
    ): RedirectResponse {
        $user = $request->user();
        abort_if($user === null, 403);

        $organization = $createOrganization->handle(
            NewOrganizationData::fromArray($request->validated()),
            $user,
        );

        // Make the new organisation active for the session, so onboarding and
        // everything after it operates inside the books just created.
        $request->session()->put(
            EstablishTenantContext::SESSION_KEY,
            $organization->getKey(),
        );

        return redirect()
            ->route('onboarding')
            ->with('success', "{$organization->name} is ready. Let's finish setting it up.");
    }
}
