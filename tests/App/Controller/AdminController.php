<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\App\Controller;

use Modufolio\Appkit\Attributes\IsGranted;
use Modufolio\Psr7\Http\Response;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Exercises #[IsGranted] enforcement: the class-level attribute applies to
 * every route, method-level attributes tighten individual routes (AND).
 */
#[IsGranted('ROLE_USER')]
class AdminController
{
    #[Route(path: '/admin/dashboard', name: 'admin_dashboard', methods: ['GET'])]
    public function dashboard(): ResponseInterface
    {
        return new Response(200, [], 'Dashboard');
    }

    #[Route(path: '/admin/settings', name: 'admin_settings', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function settings(): ResponseInterface
    {
        return new Response(200, [], 'Settings');
    }

    #[Route(path: '/admin/audit', name: 'admin_audit', methods: ['GET'])]
    #[IsGranted(['ROLE_ADMIN', 'ROLE_AUDITOR'])]
    public function audit(): ResponseInterface
    {
        return new Response(200, [], 'Audit');
    }

    /**
     * The role check applies only to the writing methods; GET stays open to
     * anyone satisfying the class-level attribute.
     */
    #[Route(path: '/admin/posts', name: 'admin_posts', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_ADMIN', methods: ['POST'])]
    public function posts(): ResponseInterface
    {
        return new Response(200, [], 'Posts');
    }
}
