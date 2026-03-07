// exit: 0
// stdout: 10
fn main() {
    let mut x: i32 = 5;
    let p: *mut i32 = &mut x as *mut i32;
    *p = 10;
    println!("{}", x);
}
