<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Csrf;
use App\Core\Session;
use App\Core\Validator;
use App\Services\UserService;
use RuntimeException;

/**
 * Profil pengguna — ubah password SENDIRI.
 *
 * PRD §6.4: hanya Admin yang boleh mengubah password sendiri.
 * Partman & Kepala Bengkel TIDAK boleh → route dilindungi RoleMiddleware('admin')
 * sehingga mereka menerima 403 meski mengetik URL langsung.
 */
final class ProfileController extends Controller
{
    /** GET /profil/password */
    public function showPasswordForm(array $params = []): void
    {
        $this->view('profile/password', [
            'title' => 'Ganti Password',
            'user'  => Auth::user(),
        ]);
    }

    /** POST /profil/password */
    public function updatePassword(array $params = []): void
    {
        if (!$this->request->isPost()) {
            $this->abort(405, 'Metode tidak diizinkan.');
        }
        Csrf::verifyOrAbort($this->request->input('_token'));

        $validator = Validator::make($this->request->post(), [
            'current_password' => 'required',
            'new_password'     => 'required|min:' . UserService::PASSWORD_MIN . '|confirmed',
        ], [
            'current_password' => 'Password saat ini',
            'new_password'     => 'Password baru',
        ]);

        if ($validator->fails()) {
            Session::flash('danger', (string) $validator->firstError());
            $this->redirect(base_url('profil/password'));
        }

        $input = $validator->validated();
        $userId = (int) (Auth::id() ?? 0);

        try {
            UserService::changeOwnPassword(
                $userId,
                (string) $input['current_password'],
                (string) $input['new_password']
            );
        } catch (RuntimeException $e) {
            Session::flash('danger', $e->getMessage());
            $this->redirect(base_url('profil/password'));
        }

        Session::flash('success', 'Password Anda berhasil diubah.');
        $this->redirect(base_url('dashboard'));
    }
}
