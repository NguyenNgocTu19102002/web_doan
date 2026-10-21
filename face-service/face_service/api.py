import base64
import binascii
import hmac
import json
import os
import threading
from fastapi import FastAPI, HTTPException, Request
from starlette.concurrency import run_in_threadpool
from .matching import identify


def create_app(api_key, engine=None):
    if len(api_key) < 32:
        raise RuntimeError('FACE_SERVICE_KEY cần ít nhất 32 ký tự.')
    if engine is None:
        from .engine import OpenCVEngine
        engine = OpenCVEngine()
    threshold = float(os.getenv('FACE_MATCH_THRESHOLD', '0.60'))
    margin = float(os.getenv('FACE_MATCH_MARGIN', '0.10'))
    if not 0 < threshold <= 1 or not 0 < margin <= 1:
        raise RuntimeError('Ngưỡng nhận diện không hợp lệ.')
    app = FastAPI(docs_url=None, redoc_url=None, openapi_url=None)
    # OpenCV detector/recognizer objects are stateful; serialize their use.
    lock = threading.Lock()

    def process(body, matching):
        if not isinstance(body, dict) or not isinstance(body.get('image'), str):
            raise HTTPException(422, 'Thiếu ảnh camera.')
        try:
            image = base64.b64decode(body['image'], validate=True)
        except (ValueError, binascii.Error):
            raise HTTPException(422, 'Ảnh không hợp lệ.')
        if not image or len(image) > 1_500_000:
            raise HTTPException(422, 'Ảnh rỗng hoặc quá lớn.')
        if matching and body.get('model_version') != engine.model_version:
            raise HTTPException(409, 'Phiên bản mẫu đã thay đổi. Cần đăng ký lại.')
        candidates = body.get('candidates', [])
        if matching and (not isinstance(candidates, list) or len(candidates) > 500):
            raise HTTPException(422, 'Danh sách lớp quá lớn hoặc không hợp lệ.')
        try:
            with lock:
                vector = engine.extract(image)
            if matching:
                return dict(identify(vector, candidates, threshold, margin), model_version=engine.model_version)
            return {'vector': vector, 'model_version': engine.model_version}
        except (ValueError, TypeError, KeyError):
            raise HTTPException(422, 'Ảnh/mẫu không hợp lệ. Chụp rõ một khuôn mặt, đủ sáng.')
        except RuntimeError:
            raise HTTPException(503, 'Dịch vụ nhận diện chưa sẵn sàng.')

    async def handle(request, matching):
        if not hmac.compare_digest(request.headers.get('X-Face-Key', '').encode('utf-8'), api_key.encode('utf-8')):
            raise HTTPException(401, 'Không được phép.')
        data = bytearray()
        async for chunk in request.stream():
            data.extend(chunk)
            if len(data) > 6_000_000:
                raise HTTPException(413, 'Request quá lớn.')
        try:
            body = json.loads(data)
        except (ValueError, UnicodeError):
            raise HTTPException(422, 'JSON không hợp lệ.')
        return await run_in_threadpool(process, body, matching)

    @app.post('/extract')
    async def extract(request: Request):
        return await handle(request, False)

    @app.post('/identify')
    async def match(request: Request):
        return await handle(request, True)

    return app


def from_env():
    return create_app(os.environ.get('FACE_SERVICE_KEY', ''))
