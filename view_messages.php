<div class="messaging-layout">
    <aside class="conversation-sidebar">
        <div class="conversation-header">
            <h2>Conversations</h2>
            <p>Logged in as: <?= htmlspecialchars($current_user_name); ?></p>
            <button id="new-conversation-btn" class="new-conv-btn"><i class="fas fa-plus mr-1"></i> New Conversation</button>
        </div>
        
        <nav class="conversation-list custom-scrollbar" id="conversation-list-area">
            <?php if (empty($conversations)): ?>
                <p class="no-data">No conversations yet.</p>
            <?php else: ?>
                <?php foreach ($conversations as $conv): 
                    $is_active = ($selected_conversation_id == $conv['conversation_id']);
                    // Initials logic simplified for brevity
                    $initials = 'U'; 
                ?>
                <a href="registrar_dashboard.php?view=messages&convo_id=<?= $conv['conversation_id'] ?>" class="conversation-item <?= $is_active ? 'active' : '' ?>">
                    <form method="POST" action="registrar_dashboard.php?view=messages" class="delete-conversation-btn-wrapper" onsubmit="return confirm('Hide this conversation?');">
                        <input type="hidden" name="action" value="delete_conversation">
                        <input type="hidden" name="conversation_id" value="<?= $conv['conversation_id'] ?>">
                        <button type="submit" class="delete-conversation-btn"><i class="fa-solid fa-times"></i></button>
                    </form>
                    <div class="conversation-avatar"><?= htmlspecialchars($initials) ?></div>
                    <div class="conversation-details">
                        <span class="conversation-name"><?= htmlspecialchars($conv['other_full_name']) ?></span>
                        <span class="conversation-last-message"><?= htmlspecialchars($conv['last_message'] ?? '...'); ?></span>
                    </div>
                    <?php if ($conv['unread_count'] > 0): ?><span class="unread-badge"><?= $conv['unread_count'] ?></span><?php endif; ?>
                </a>
                <?php endforeach; ?>
            <?php endif; ?>
        </nav>
    </aside>

    <div class="message-view" style="transform: translateX(<?php echo ($view === 'messages' && $selected_conversation_id) ? '0' : '100%'; ?>);">
        <header class="message-header">
            <a href="registrar_dashboard.php?view=messages" class="back-button"><i class="fa-solid fa-arrow-left"></i></a>
            <i class="fa-solid fa-user"></i>
            <div><h3><?= htmlspecialchars($other_user_name); ?></h3><span><?= htmlspecialchars($other_user_role); ?></span></div>
        </header>
        <div id="message-area" class="custom-scrollbar">
            <?php if ($selected_conversation_id && $is_part_of_conversation): ?>
                <?php if (!empty($messages)): ?>
                    <?php foreach ($messages as $msg): ?>
                    <div class="message-bubble-container <?= ($msg['sender_id'] == $current_user_id) ? 'sent' : 'received'; ?>">
                        <div class="message-bubble <?= ($msg['sender_id'] == $current_user_id) ? 'sent' : 'received'; ?>">
                            <?php if ($msg['sender_id'] != $current_user_id): ?><p class="sender-name"><?= htmlspecialchars($msg['sender_full_name'] ?? 'User'); ?></p><?php endif; ?>
                            <p><?= nl2br(htmlspecialchars($msg['body'])); ?></p>
                            <p class="timestamp"><?= date('M j, g:i A', strtotime($msg['sent_at'])); ?></p>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?><p class="no-data">No messages yet. Say hello!</p><?php endif; ?>
            <?php else: ?><p class="select-conversation-prompt">Select a conversation.</p><?php endif; ?>
        </div>
        <?php if ($selected_conversation_id && $is_part_of_conversation): ?>
        <footer class="message-input-area">
            <?php if ($send_error): ?><p class="message error"><?= htmlspecialchars($send_error); ?></p><?php endif; ?>
            <form action="registrar_dashboard.php?view=messages&convo_id=<?= $selected_conversation_id; ?>" method="POST">
                <input type="hidden" name="receiver_id" value="<?= htmlspecialchars($current_conversation_receiver_id); ?>">
                <textarea name="body" placeholder="Type your message..." rows="1" required></textarea>
                <button type="submit" name="send_message"><i class="fas fa-paper-plane"></i></button>
            </form>
        </footer>
        <?php endif; ?>
    </div>
</div>