(function () {
    'use strict';
    var app = document.getElementById('faceApp');
    if (!app) return;
    var el = function (id) { return document.getElementById(id); };
    var stream = null, samples = [], confirmation = null, busy = false, expiry = null, cameraGeneration = 0;
    var video = el('faceVideo');
    var autoRunning = false, autoTimer = null, autoGeneration = 0, autoRunId = '', autoSeen = Object.create(null);

    function autoControls() {
        app.querySelectorAll('button,select,input').forEach(function (item) {
            item.disabled = autoRunning || busy;
        });
        el('stopAutoFace').disabled = !autoRunning;
        el('stopCamera').disabled = false;
    }
    function stopAutomatic() {
        var wasRunning = autoRunning;
        autoRunning = false; autoGeneration++;
        clearTimeout(autoTimer);
        autoControls();
        if (wasRunning) el('autoFaceStatus').textContent = 'Đã dừng tự động. Yêu cầu đã gửi trước khi dừng có thể vẫn được xử lý.';
    }
    function attendanceSound() {
        try {
            var Audio = window.AudioContext || window.webkitAudioContext;
            var audio = new Audio(), oscillator = audio.createOscillator(), gain = audio.createGain();
            oscillator.connect(gain); gain.connect(audio.destination);
            gain.gain.value = 0.1; oscillator.frequency.value = 880;
            oscillator.start(); oscillator.stop(audio.currentTime + 0.15);
            oscillator.onended = function () { audio.close(); };
        } catch (e) { /* Browsers may restrict audio; attendance remains visible. */ }
    }
    async function automaticTick(generation) {
        if (!autoRunning || generation !== autoGeneration || document.hidden) return;
        busy = true;
        try {
            var result = await request('auto_scan', {image: capture(), mode: el('attendanceMode').value, run_id: autoRunId});
            if (!autoRunning || generation !== autoGeneration) return;
            if (!result.matched) {
                el('autoFaceStatus').textContent = 'Đang chờ khuôn mặt rõ…';
            } else {
                var name = [result.student.ten_thanh, result.student.ho_ten].filter(Boolean).join(' ');
                if (!result.recorded) {
                    el('autoFaceStatus').textContent = name + ' — đang xử lý…';
                } else {
                    el('autoFaceStatus').textContent = name + (result.already_recorded ? ' — đã được điểm danh trước đó.' : ' — ' + result.message);
                    var key = el('faceDate').value + '|' + el('faceActivity').value + '|' + result.student.id;
                    if (!autoSeen[key]) {
                        autoSeen[key] = true;
                        var row = document.createElement('li'); row.className = 'list-group-item';
                        row.textContent = name + ' — ' + result.status + (result.already_recorded ? ' (đã ghi trước đó)' : '');
                        el('autoFaceHistory').prepend(row);
                        while (el('autoFaceHistory').children.length > 30) el('autoFaceHistory').lastElementChild.remove();
                        if (!result.already_recorded) attendanceSound();
                    }
                }
            }
        } catch (error) {
            if (!autoRunning || generation !== autoGeneration) return;
            // 422 is an ordinary empty/unclear camera frame. Other failures stop the loop.
            if (error.status === 422) el('autoFaceStatus').textContent = 'Đưa một khuôn mặt rõ, đủ sáng vào khung…';
            else { stopAutomatic(); message(error.message, true); }
        } finally {
            busy = false;
            autoControls();
            if (autoRunning && generation === autoGeneration) autoTimer = setTimeout(function () { automaticTick(generation); }, 2200);
        }
    }
    el('startAutoFace').addEventListener('click', async function () {
        if (busy || autoRunning) return;
        if (!stream) await start();
        if (!stream || document.hidden || busy) return;
        clearConfirmation();
        var bytes = new Uint8Array(16); window.crypto.getRandomValues(bytes);
        autoRunId = Array.from(bytes).map(function (v) { return v.toString(16).padStart(2, '0'); }).join('');
        autoRunning = true; autoGeneration++;
        autoControls(); message('Đang tự động điểm danh. Từng em đứng rõ trước camera cho đến khi có kết quả.');
        automaticTick(autoGeneration);
    });
    el('stopAutoFace').addEventListener('click', stopAutomatic);

    function message(text, error) {
        el('faceMessage').textContent = text;
        el('faceMessage').className = 'alert ' + (error ? 'alert-warning' : 'alert-info');
    }
    function clearConfirmation() {
        confirmation = null;
        clearTimeout(expiry);
        el('faceResult').hidden = true;
        el('preview').removeAttribute('src');
    }
    function clearSamples() {
        samples = [];
        el('faceSamples').replaceChildren();
        el('captureSample').textContent = 'Chụp ảnh mẫu (0/3)';
    }
    function stop() {
        stopAutomatic();
        cameraGeneration++;
        if (stream) stream.getTracks().forEach(function (track) { track.stop(); });
        stream = null;
        video.srcObject = null;
    }
    async function start() {
        if (busy) return;
        stop();
        var generation = cameraGeneration;
        clearConfirmation();
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            message('Camera cần HTTPS (hoặc localhost) và trình duyệt hỗ trợ.', true);
            return;
        }
        busy = true;
        try {
            var opened = await navigator.mediaDevices.getUserMedia({audio: false, video: {
                facingMode: {ideal: el('cameraFacing').value},
                width: {ideal: 960}, height: {ideal: 720}
            }});
            if (generation !== cameraGeneration || document.hidden) {
                opened.getTracks().forEach(function (track) { track.stop(); });
                return;
            }
            stream = opened;
            video.srcObject = stream;
            await video.play();
            message('Camera đã sẵn sàng. Chụp một khuôn mặt rõ, đủ sáng.');
        } catch (error) {
            stop();
            message('Không mở được camera. Kiểm tra quyền camera và đóng ứng dụng đang dùng camera.', true);
        } finally { busy = false; }
    }
    function capture() {
        if (!stream || !video.videoWidth || video.readyState < 2) throw new Error('Hãy mở camera và đợi hình ảnh sẵn sàng.');
        var scale = Math.min(1, 960 / Math.max(video.videoWidth, video.videoHeight));
        var canvas = document.createElement('canvas');
        canvas.width = Math.round(video.videoWidth * scale);
        canvas.height = Math.round(video.videoHeight * scale);
        canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);
        return canvas.toDataURL('image/jpeg', 0.85).split(',')[1];
    }
    async function request(action, values) {
        if (!el('faceDate').checkValidity() || !el('faceDate').value) throw new Error('Chọn ngày hợp lệ, không ở tương lai.');
        var body = new FormData();
        body.set('class_id', app.dataset.classId);
        body.set('csrf', app.dataset.csrf);
        body.set('date', el('faceDate').value);
        body.set('activity', el('faceActivity').value);
        body.set('action', action);
        Object.keys(values).forEach(function (key) {
            if (Array.isArray(values[key])) values[key].forEach(function (v) { body.append(key + '[]', v); });
            else body.set(key, values[key]);
        });
        var controller = new AbortController();
        var timer = setTimeout(function () { controller.abort(); }, action === 'enroll' ? 90000 : 35000);
        try {
            var response = await fetch('face_api.php', {method: 'POST', body: body, credentials: 'same-origin', signal: controller.signal});
            var result;
            try { result = await response.json(); } catch (e) { throw new Error('Máy chủ không trả kết quả hợp lệ. Kiểm tra đăng nhập và cấu hình.'); }
            if (!response.ok || !result.ok) {
                var failure = new Error(result.message || 'Thao tác thất bại.');
                failure.status = response.status;
                throw failure;
            }
            return result.data;
        } catch (error) {
            if (error.name === 'AbortError' || error instanceof TypeError) {
                throw new Error('Mất kết nối hoặc quá thời gian chờ. Nếu đang lưu, kiểm tra trang lớp trước khi thử lại.');
            }
            throw error;
        } finally { clearTimeout(timer); }
    }
    async function run(task) {
        if (busy) return;
        busy = true;
        app.querySelectorAll('button,select,input').forEach(function (item) { item.disabled = true; });
        try { await task(); }
        catch (error) { clearConfirmation(); message(error.message, true); }
        finally {
            busy = false;
            autoControls();
        }
    }
    el('startCamera').addEventListener('click', start);
    el('stopCamera').addEventListener('click', function () { stop(); clearConfirmation(); });
    el('cameraFacing').addEventListener('change', start);
    ['faceDate', 'faceActivity', 'attendanceMode'].forEach(function (id) {
        el(id).addEventListener('change', function () { clearConfirmation(); stopAutomatic(); });
    });
    el('identifyFace').addEventListener('click', function () { run(async function () {
        clearConfirmation();
        var image = capture();
        message('Đang nhận diện…');
        var result = await request('identify', {image: image});
        if (!result.matched) { message(result.message, true); return; }
        confirmation = result.confirmation_token;
        el('matchedName').textContent = [result.student.ten_thanh, result.student.ho_ten].filter(Boolean).join(' ');
        el('preview').src = 'data:image/jpeg;base64,' + image;
        el('faceResult').hidden = false;
        expiry = setTimeout(function () { clearConfirmation(); message('Kết quả hết hạn. Chụp lại để nhận diện.', true); }, 120000);
        message('Kiểm tra tên và khuôn mặt, sau đó xác nhận để lưu.');
    }); });
    el('confirmFace').addEventListener('click', function () { run(async function () {
        if (!confirmation) throw new Error('Hãy chụp và nhận diện lại.');
        var token = confirmation;
        clearConfirmation();
        var result = await request('confirm', {confirmation_token: token, mode: el('attendanceMode').value});
        message(result.message);
    }); });
    el('cancelFace').addEventListener('click', clearConfirmation);
    el('faceStudent').addEventListener('change', function () {
        clearSamples(); clearConfirmation();
    });
    el('clearSamples').addEventListener('click', clearSamples);
    el('captureSample').addEventListener('click', function () {
        if (busy) return;
        try {
            if (!el('faceStudent').value) throw new Error('Chọn đoàn sinh trước khi chụp mẫu.');
            if (samples.length >= 3) throw new Error('Đã đủ 3 ảnh. Bấm lưu hoặc chụp lại từ đầu.');
            var image = capture(); samples.push(image);
            var preview = document.createElement('img');
            preview.src = 'data:image/jpeg;base64,' + image; preview.alt = 'Ảnh mẫu ' + samples.length;
            el('faceSamples').appendChild(preview);
            el('captureSample').textContent = 'Chụp ảnh mẫu (' + samples.length + '/3)';
        } catch (error) { message(error.message, true); }
    });
    el('enrollFace').addEventListener('click', function () { run(async function () {
        if (!el('faceStudent').value || samples.length !== 3) throw new Error('Cần chọn đoàn sinh và chụp đủ 3 ảnh.');
        message('Đang kiểm tra và lưu mẫu…');
        var result = await request('enroll', {student_id: el('faceStudent').value, images: samples});
        clearSamples(); clearConfirmation();
        var option = el('faceStudent').selectedOptions[0];
        option.textContent = option.textContent.replace(/ — (Chưa có mẫu|Đã có mẫu)$/, '') + ' — Đã có mẫu';

        message(result.message);
    }); });
    el('deleteFace').addEventListener('click', function () {
        if (!el('faceStudent').value || busy) return;
        if (!window.confirm('Xóa mẫu khuôn mặt của đoàn sinh đã chọn?')) return;
        run(async function () {
            var result = await request('delete', {student_id: el('faceStudent').value});
            clearSamples(); clearConfirmation();
            var option = el('faceStudent').selectedOptions[0];
            option.textContent = option.textContent.replace(/ — (Chưa có mẫu|Đã có mẫu)$/, '') + ' — Chưa có mẫu';
            message(result.message);
        });
    });
    window.addEventListener('pagehide', function () { stop(); clearSamples(); clearConfirmation(); });
    document.addEventListener('visibilitychange', function () {
        if (document.hidden) { stop(); clearSamples(); clearConfirmation(); }
    });
}());
