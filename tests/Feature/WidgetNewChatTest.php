<?php

namespace Tests\Feature;

use App\Models\Chat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

class WidgetNewChatTest extends TestCase
{
    use RefreshDatabase;

    public function test_widget_can_start_new_chat_after_close(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);

        $visitorId = 'visitor_12345';
        $companyId = '123e4567-e89b-12d3-a456-426614174000';

        $first = $this->postJson('/api/widget/chat', [
            'visitor_id' => $visitorId,
            'company_id' => $companyId,
            'current_url' => 'https://example.test/a',
        ]);

        $first->assertOk();
        $firstChatId = $first->json('chat.id');
        $this->assertNotNull($firstChatId);
        $this->assertSame('open', $first->json('chat.status'));

        Chat::query()->whereKey($firstChatId)->update(['status' => 'close']);

        $same = $this->postJson('/api/widget/chat', [
            'visitor_id' => $visitorId,
            'company_id' => $companyId,
            'current_url' => 'https://example.test/b',
        ]);
        $same->assertOk();
        $this->assertSame('close', $same->json('chat.status'));
        $this->assertSame($firstChatId, $same->json('chat.id'));

        $new = $this->postJson('/api/widget/chat/new', [
            'visitor_id' => $visitorId,
            'company_id' => $companyId,
            'current_url' => 'https://example.test/c',
        ]);
        $new->assertOk();
        $this->assertSame('open', $new->json('chat.status'));
        $this->assertNotSame($firstChatId, $new->json('chat.id'));
        $this->assertSame($visitorId, $new->json('chat.visitor_id'));
    }
}
