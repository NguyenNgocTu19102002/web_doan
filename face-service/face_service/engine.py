import io
import os
from pathlib import Path
import cv2
import numpy as np
from PIL import Image, UnidentifiedImageError
from .matching import validate_vector


class OpenCVEngine:
    model_version = 'opencv-sface-2021dec-yunet-2023mar-v1'

    def __init__(self):
        self.detector = self.recognizer = None

    def _load(self):
        if self.detector is not None:
            return
        root = Path(os.environ.get('FACE_MODEL_DIR', '/models'))
        try:
            detector = cv2.FaceDetectorYN.create(str(root / 'face_detection_yunet_2023mar.onnx'), '', (320, 320), .9)
            recognizer = cv2.FaceRecognizerSF.create(str(root / 'face_recognition_sface_2021dec.onnx'), '')
        except cv2.error as exc:
            raise RuntimeError('Models unavailable') from exc
        self.detector, self.recognizer = detector, recognizer

    def extract(self, encoded):
        try:
            with Image.open(io.BytesIO(encoded)) as image:
                if image.format not in ('JPEG', 'PNG') or min(image.size) < 120 or max(image.size) > 1920:
                    raise ValueError('Kích thước hoặc loại ảnh không hợp lệ.')
                image.verify()
        except (UnidentifiedImageError, OSError, Image.DecompressionBombError) as exc:
            raise ValueError('Ảnh không hợp lệ.') from exc
        self._load()
        try:
            image = cv2.imdecode(np.frombuffer(encoded, dtype=np.uint8), cv2.IMREAD_COLOR)
            if image is None:
                raise ValueError('Không đọc được ảnh.')
            self.detector.setInputSize((image.shape[1], image.shape[0]))
            _, faces = self.detector.detect(image)
            if faces is None or len(faces) != 1:
                raise ValueError('Cần đúng một khuôn mặt.')
            x, y, w, h = faces[0][:4].astype(int)
            if min(w, h) < 80 or x < 0 or y < 0 or x+w > image.shape[1] or y+h > image.shape[0]:
                raise ValueError('Đưa toàn bộ khuôn mặt gần camera hơn.')
            crop = image[y:y+h, x:x+w]
            gray = cv2.cvtColor(crop, cv2.COLOR_BGR2GRAY)
            if gray.mean() < 40 or gray.mean() > 225 or cv2.Laplacian(gray, cv2.CV_64F).var() < 30:
                raise ValueError('Ảnh mờ hoặc ánh sáng không phù hợp.')
            aligned = self.recognizer.alignCrop(image, faces[0])
            return validate_vector(self.recognizer.feature(aligned).flatten().tolist())
        except cv2.error as exc:
            raise ValueError('Không xử lý được khuôn mặt.') from exc
