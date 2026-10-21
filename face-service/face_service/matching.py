"""Deterministic cosine matching; no image storage or database access."""
import math


def validate_vector(values):
    if not isinstance(values, list) or len(values) != 128:
        raise ValueError('Mẫu khuôn mặt không đúng kích thước.')
    if any(isinstance(v, bool) or not isinstance(v, (int, float)) or not math.isfinite(v) for v in values):
        raise ValueError('Mẫu khuôn mặt không hợp lệ.')
    norm = math.sqrt(sum(v * v for v in values))
    if not math.isfinite(norm) or norm < 1e-9:
        raise ValueError('Mẫu khuôn mặt rỗng.')
    return [v / norm for v in values]


def identify(query, candidates, threshold, margin):
    query = validate_vector(query)
    ranked, seen = [], set()
    for candidate in candidates:
        sid = candidate['student_id']
        if type(sid) is not int or sid <= 0 or sid in seen:
            raise ValueError('Danh sách đoàn sinh không hợp lệ.')
        seen.add(sid)
        vectors = candidate['vectors']
        if not vectors or len(vectors) > 5:
            raise ValueError('Số lượng mẫu không hợp lệ.')
        scores = [sum(a * b for a, b in zip(query, validate_vector(v))) for v in vectors]
        ranked.append((max(scores), sid))
    ranked.sort(reverse=True)
    if not ranked:
        return {'student_id': None, 'score': 0.0, 'reason': 'no_templates'}
    score, sid = ranked[0]
    if score < threshold:
        return {'student_id': None, 'score': score, 'reason': 'unknown'}
    if len(ranked) > 1 and score - ranked[1][0] < margin:
        return {'student_id': None, 'score': score, 'reason': 'ambiguous'}
    return {'student_id': sid, 'score': score, 'reason': 'matched'}
