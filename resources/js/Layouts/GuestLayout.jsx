import { Head } from '@inertiajs/react';

export default function GuestLayout({ children, title }) {
    return (
        <>
            <Head title={title} />
            <div className="main-wrapper login-body">
                <div className="login-wrapper">
                    <div className="container">
                        <div className="row justify-content-center">
                            <div className="col-md-6 col-lg-5 col-xl-4">
                                <div className="loginbox">
                                    <div className="login-right">
                                        <div className="login-right-wrap">
                                            <div className="text-center mb-4">
                                                <img
                                                    src="/system/images/preclinic/logo.svg"
                                                    alt="EasyEye"
                                                    style={{ maxWidth: 180 }}
                                                />
                                            </div>
                                            {children}
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </>
    );
}
