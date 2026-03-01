// exit: 0
// stdout: 6
fn main() {
    let mut x: i32 = 4;
    let p: &mut i32 = &mut x;
    *p += 2;
    println!("{}", x);
}
