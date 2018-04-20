#-------------------------------------------------------------------------------
#| Configuration |
#-----------------
%define debug_package %{nil}
%define __os_install_post %{nil}
%define find-provides %{nil}
AutoReqProv: 0

%define icinga_version 1.0.1
%define neteye_version 1.0.1

%define module_name fileshipper

%define module_home_dir %{_datadir}/icingaweb2/modules/%{module_name}
%define module_conf_dir /neteye/shared/icingaweb2/conf/modules/%{module_name}/
%define ne_secure_install_dir %{_datadir}/neteye/secure_install/
#-------------------------------------------------------------------------------
#| Package info |
#----------------
Name:		icingaweb2-module-%{module_name}
License:	GPLv3
Group:		Productivity/Networking/Diagnostic
Version:	%{icinga_version}_neteye%{neteye_version}
Release:	2
Summary:	%{name} Module for Icingaweb2
Source0:	%{name}.tar.gz

Requires:       php-soap
Requires:       rh-php71-php-soap
# Need existing users
Requires(pre):	httpd

%description
%{name} Module for Icinga Web 2

#-------------------------------------------------------------------------------
#| Prepare |
#-----------
%prep
%setup -c

#-------------------------------------------------------------------------------
#| Build |
#---------
%build

#-------------------------------------------------------------------------------
#| Cleanup |
#-----------
%clean
rm -rf %{buildroot}

#-------------------------------------------------------------------------------
#| Install |
#-----------
%install
rm -rf %{buildroot}

# install module data in icingaweb2 home directory
install -d -m0755 %{buildroot}%{module_home_dir}
#cp -prv application %{buildroot}%{module_home_dir}
cp -prv library doc %{buildroot}%{module_home_dir}
cp -pv *.php *.info *.md LICENSE %{buildroot}%{module_home_dir}


#-------------------------------------------------------------------------------
#| Pre install |
#----------------
%pre

#-------------------------------------------------------------------------------
#| Post install |
#----------------
%post

#-------------------------------------------------------------------------------
#| Pre uninstall |
#-----------------
#%preun

#-------------------------------------------------------------------------------
#| Post uninstall |
#------------------
#%postun

#-------------------------------------------------------------------------------
#| Files |
#---------
%files
%defattr(0644, apache, root, 0755)
%{module_home_dir}

#-------------------------------------------------------------------------------
#| ChangeLog |
#-------------
%changelog
* Fri Apr 20 2018 Benjamin Groeber <Benjamin.Groeber@wuerth-phoenix.com> - 1.0.1_neteye1.0.1-2
- Add dependency for rh-php71-php-soap

* Wed Dec 20 2017 Benjamin Groeber <Benjamin.Groeber@wuerth-phoenix.com> - 1.0.1_neteye1.0.1-1
- Cherry picked XLSX support from Master

* Tue Dec 19 2017 Benjamin Groeber <Benjamin.Groeber@wuerth-phoenix.com> - 1.0.1_neteye1.0.0-1
- Initial release
