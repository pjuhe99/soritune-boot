<?php
/**
 * Notice service — 공지사항 CRUD + listing + 검증.
 *
 * 모든 함수는 PDO + 명시적 인자를 받는다 (세션/슈퍼글로벌 의존 X).
 * 검증 위반은 InvalidArgumentException.
 * cohort mismatch / row not found 도 InvalidArgumentException.
 */

const NOTICE_TITLE_MAX = 255;

function _noticeValidateContent(string $title, string $body, int $isVisible): array {
    $title = trim($title);
    $body  = trim($body);
    if ($title === '') {
        throw new InvalidArgumentException('제목을 입력해주세요.');
    }
    if (mb_strlen($title) > NOTICE_TITLE_MAX) {
        throw new InvalidArgumentException('제목은 ' . NOTICE_TITLE_MAX . '자 이하로 입력해주세요.');
    }
    if ($body === '') {
        throw new InvalidArgumentException('본문을 입력해주세요.');
    }
    if ($isVisible !== 0 && $isVisible !== 1) {
        throw new InvalidArgumentException('잘못된 is_visible 값입니다.');
    }
    return [$title, $body, $isVisible];
}

function _noticeRequireOwnedRow(PDO $db, int $cohortId, int $id): array {
    $stmt = $db->prepare("SELECT * FROM notices WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new InvalidArgumentException('공지를 찾을 수 없습니다.');
    }
    if ((int)$row['cohort_id'] !== $cohortId) {
        throw new InvalidArgumentException('다른 기수의 공지는 수정할 수 없습니다.');
    }
    return $row;
}

function noticeListAdmin(PDO $db, int $cohortId): array {
    $stmt = $db->prepare("
        SELECT id, cohort_id, title, body_markdown, is_visible,
               created_by_admin_id, created_by_admin_name,
               created_at, updated_at
          FROM notices
         WHERE cohort_id = ?
         ORDER BY is_visible DESC, created_at DESC, id DESC
    ");
    $stmt->execute([$cohortId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function noticeListMember(PDO $db, int $cohortId): array {
    $stmt = $db->prepare("
        SELECT id, title, body_markdown,
               created_by_admin_name, created_at
          FROM notices
         WHERE cohort_id = ? AND is_visible = 1
         ORDER BY created_at DESC, id DESC
    ");
    $stmt->execute([$cohortId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function noticeCreate(
    PDO $db,
    int $cohortId,
    int $adminId,
    string $adminName,
    string $title,
    string $bodyMarkdown,
    int $isVisible
): int {
    [$title, $body, $isVisible] = _noticeValidateContent($title, $bodyMarkdown, $isVisible);
    $stmt = $db->prepare("
        INSERT INTO notices
            (cohort_id, title, body_markdown, is_visible,
             created_by_admin_id, created_by_admin_name,
             created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())
    ");
    $stmt->execute([$cohortId, $title, $body, $isVisible, $adminId, $adminName]);
    return (int)$db->lastInsertId();
}

function noticeUpdate(PDO $db, int $cohortId, int $id, string $title, string $bodyMarkdown): void {
    _noticeRequireOwnedRow($db, $cohortId, $id);
    [$title, $body] = _noticeValidateContent($title, $bodyMarkdown, 0); // isVisible 자리만 placeholder
    $stmt = $db->prepare("
        UPDATE notices
           SET title = ?, body_markdown = ?, updated_at = NOW()
         WHERE id = ? AND cohort_id = ?
    ");
    $stmt->execute([$title, $body, $id, $cohortId]);
}

function noticeToggleVisible(PDO $db, int $cohortId, int $id, int $isVisible): int {
    _noticeRequireOwnedRow($db, $cohortId, $id);
    if ($isVisible !== 0 && $isVisible !== 1) {
        throw new InvalidArgumentException('잘못된 is_visible 값입니다.');
    }
    $stmt = $db->prepare("
        UPDATE notices
           SET is_visible = ?, updated_at = NOW()
         WHERE id = ? AND cohort_id = ?
    ");
    $stmt->execute([$isVisible, $id, $cohortId]);
    return $isVisible;
}

function noticeDelete(PDO $db, int $cohortId, int $id): void {
    _noticeRequireOwnedRow($db, $cohortId, $id);
    $stmt = $db->prepare("DELETE FROM notices WHERE id = ? AND cohort_id = ?");
    $stmt->execute([$id, $cohortId]);
}
